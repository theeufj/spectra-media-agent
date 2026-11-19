<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Recommendation;
use App\Models\VideoCollateral;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent;
use App\Services\Agents\FacebookLearningPhaseAgent;
use App\Services\Agents\LinkedInCampaignOptimizationAgent;
use App\Services\Agents\SelfHealingAgent;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAssets;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use App\Services\GoogleAds\PerformanceMaxServices\HealAssetGroupStrength;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs every 4 hours. Checks live campaigns for policy issues and restores
 * approved assets where possible.
 */
class RunSelfHealingChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public $tries = 1;

    public $timeout = 600;

    public function handle(
        SelfHealingAgent $selfHealingAgent,
        CampaignDiagnosticsAgent $diagnosticsAgent,
        CampaignRemediationAgent $remediationAgent,
        FacebookLearningPhaseAgent $fbLearningAgent,
        FacebookAdRelevanceDiagnosticsAgent $fbRelevanceAgent,
        LinkedInCampaignOptimizationAgent $linkedInAgent
    ): void {
        Log::info('RunSelfHealingChecks: Starting 4-hour healing pass');
        $runStart = $this->startRun();

        $campaigns = Campaign::with('customer')
            ->serving()
            ->where(fn ($q) => $q->whereNotNull('google_ads_campaign_id')
                ->orWhereNotNull('facebook_ads_campaign_id')
                ->orWhereNotNull('microsoft_ads_campaign_id')
                ->orWhereNotNull('linkedin_campaign_id'))
            ->get();

        $healed = 0;
        $errors = 0;
        $warnings = 0;

        foreach ($campaigns as $campaign) {
            $lock = Cache::lock("self_heal:campaign:{$campaign->id}", 3600);
            if (! $lock->get()) {
                continue;
            }

            try {
                // Pass 1: existing healing (disapproved ads, budget, delivery)
                $results = $selfHealingAgent->heal($campaign);
                $healed += count($results['actions_taken'] ?? []);
                $errors += count($results['errors'] ?? []);
                $warnings += count($results['warnings'] ?? []);

                // Pass 2: strategic diagnosis → autonomous remediation
                $findings = $diagnosticsAgent->diagnose($campaign);
                if (! empty($findings)) {
                    Log::info('RunSelfHealingChecks: Diagnostic findings for campaign '.$campaign->id, [
                        'count' => count($findings),
                        'types' => array_column($findings, 'type'),
                    ]);
                    $remediationResult = $remediationAgent->remediate($campaign, $findings);
                    $healed += count($remediationResult['actions_taken'] ?? []);
                    $errors += count($remediationResult['errors'] ?? []);
                    $warnings += count($remediationResult['unresolved'] ?? []) + count($remediationResult['alerts_sent'] ?? []);
                }

                if ($campaign->facebook_ads_campaign_id) {
                    $fbLearningAgent->analyze($campaign);
                    if (! FacebookLearningPhaseAgent::isOnHold($campaign)) {
                        $fbRelevanceAgent->analyze($campaign);
                    }
                }

                if ($campaign->linkedin_campaign_id) {
                    $linkedInAgent->analyze($campaign);
                }

                // Pass 3 (Google): heal POOR/AVERAGE PMax ad strength and extensions.
                // Conversion goals are diagnosed per strategy in pass 2, with guarded campaign-only repairs.
                if ($campaign->google_ads_campaign_id) {
                    try {
                        $guard = app(GoogleAdStrengthRepair::class);
                        $strategy = $guard->strategyForCampaign($campaign);
                        if (! $strategy || ($blocked = $guard->campaignMutationBlocked($campaign, $strategy))) {
                            Log::info('RunSelfHealingChecks: Google strength pass held', ['campaign_id' => $campaign->id, 'reason' => $blocked ?? 'strategy_not_approved']);
                            $warnings++;
                        } else {
                            $sitelinkTarget = null;
                            foreach (app(HealAssetGroupStrength::class, ['customer' => $campaign->customer])->heal($campaign) as $r) {
                                if (! empty($r['requested_assets']['SITELINK'])) {
                                    // Bound repeated requests while Google evaluates newly linked destinations.
                                    $sitelinkTarget = 8;
                                }
                                $addedCount = array_sum($r['added']);
                                if ($addedCount > 0) {
                                    $healed += $addedCount;
                                    $imgCount = array_sum(array_intersect_key($r['added'], array_flip(['MARKETING_IMAGE', 'SQUARE_IMAGE', 'PORTRAIT_IMAGE', 'LOGO'])));
                                    $textCount = $addedCount - $imgCount;
                                    $parts = [];
                                    if ($textCount > 0) {
                                        $parts[] = "{$textCount} text asset(s)";
                                    }
                                    if ($imgCount > 0) {
                                        $parts[] = "{$imgCount} image(s)";
                                    }
                                    $what = implode(' + ', $parts);

                                    Recommendation::create([
                                        'campaign_id' => $campaign->id,
                                        'type' => 'AD_STRENGTH',
                                        'rationale' => "Added {$what} to '{$r['asset_group']}' (ad strength was {$r['ad_strength']})",
                                        'status' => 'applied',
                                        'requires_approval' => false,
                                    ]);
                                    AgentActivity::record(
                                        'maintenance', 'ad_strength_healed',
                                        "Added {$what} to '{$r['asset_group']}' (ad strength was {$r['ad_strength']})",
                                        $campaign->customer_id, $campaign->id, $r
                                    );
                                }

                                // Missing video is a major ad-strength gap PMax weights heavily.
                                if ($r['requires_review']) {
                                    $requested = $r['requested_assets'];
                                    unset($requested['SITELINK']);
                                    $formats = [];
                                    foreach ($requested as $field => $count) {
                                        $label = match ($field) {
                                            'MARKETING_IMAGE' => 'landscape images',
                                            'SQUARE_IMAGE' => 'square images',
                                            'PORTRAIT_IMAGE' => 'portrait images',
                                            'YOUTUBE_VIDEO_VERTICAL' => 'vertical YouTube videos',
                                            'YOUTUBE_VIDEO_HORIZONTAL' => 'horizontal YouTube videos',
                                            'YOUTUBE_VIDEO_SQUARE' => 'square YouTube videos',
                                            default => strtolower(str_replace('_', ' ', $field)),
                                        };
                                        $formats[] = $count.' × '.$label;
                                    }
                                    $message = "Review more Performance Max assets for '{$r['asset_group']}' before adding them.";
                                    if ($formats) {
                                        $message .= ' Google requests '.implode(', ', $formats).'.';
                                    }
                                    $target = $r['resource'];
                                    $pending = Recommendation::where('campaign_id', $campaign->id)->where('type', 'AD_STRENGTH')
                                        ->where('status', 'pending')->where(function ($query) use ($target, $r) {
                                            $query->where('target_entity->asset_group', $target)
                                                ->orWhere('rationale', "Review more Performance Max assets for '{$r['asset_group']}' before adding them.");
                                        })->firstOrNew();
                                    $pending->fill(['campaign_id' => $campaign->id, 'type' => 'AD_STRENGTH', 'status' => 'pending',
                                        'requires_approval' => true, 'target_entity' => ['asset_group' => $target],
                                        'rationale' => $message, 'evidence' => ['requested_assets' => $requested]])->save();
                                }
                                if (in_array('YOUTUBE_VIDEO', $r['missing_media'], true)) {
                                    $this->ensurePMaxVideo($campaign, $strategy);
                                }
                            }

                            // Ensure the campaign has sitelinks (improves ad strength + real estate).
                            $sitelinksAdded = app(CreateSitelinkAssets::class, ['customer' => $campaign->customer])->heal($campaign, $sitelinkTarget);
                            if ($sitelinksAdded > 0) {
                                $healed += $sitelinksAdded;
                                Recommendation::create([
                                    'campaign_id' => $campaign->id,
                                    'type' => 'SITELINKS',
                                    'rationale' => "Added {$sitelinksAdded} sitelink(s) to improve ad strength",
                                    'status' => 'applied',
                                    'requires_approval' => false,
                                ]);
                                AgentActivity::record(
                                    'maintenance', 'sitelinks_added',
                                    "Added {$sitelinksAdded} sitelink(s) to '{$campaign->name}'",
                                    $campaign->customer_id, $campaign->id, []
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        report($e);
                        Log::error('RunSelfHealingChecks: Google strength/extension pass failed for campaign '.$campaign->id.': '.$e->getMessage());
                        $errors++;
                    }
                }

                if (! empty($results['actions_taken'])) {
                    AgentActivity::record(
                        'maintenance',
                        'self_healed',
                        'Fixed '.count($results['actions_taken']).' issue(s) in "'.$campaign->name.'"',
                        $campaign->customer_id,
                        $campaign->id,
                        ['actions' => $results['actions_taken']]
                    );
                }
            } catch (\Throwable $e) {
                report($e);
                Log::error('RunSelfHealingChecks: Error processing campaign '.$campaign->id.': '.$e->getMessage());
                $errors++;
            } finally {
                $lock->release();
            }
        }

        Log::info('RunSelfHealingChecks: Completed', [
            'campaigns' => $campaigns->count(),
            'healed' => $healed,
            'errors' => $errors,
            'warnings' => $warnings,
        ]);

        $this->finishRun($runStart, actions: $healed, errors: $errors, warnings: $warnings, scope: $campaigns->count().' campaigns');
    }

    /**
     * Link a selected completed video or generate a new draft for review.
     */
    private function ensurePMaxVideo(Campaign $campaign, \App\Models\Strategy $strategy): void
    {
        $key = "pmax_video_heal:{$campaign->id}";
        if (Cache::has($key)) {
            return;
        }

        if (! $campaign->customer || app(GoogleAdStrengthRepair::class)->campaignMutationBlocked($campaign, $strategy)) {
            return;
        }

        $customerId = $campaign->customer->cleanGoogleCustomerId();

        $videos = VideoCollateral::forStrategy($strategy)->where('is_active', true)
            ->where('platform', 'Google Ads (Performance Max)')->whereIn('status', ['pending', 'generating', 'completed'])->get();
        $approvedIds = $strategy->creative_approval['video_ids'] ?? [];
        $selected = $videos->filter(fn (VideoCollateral $video) => $video->status === 'completed' && $video->should_deploy
            && in_array($video->id, $approvedIds, true))->modelKeys();
        if ($selected !== []) {
            UploadPMaxVideoAssets::dispatch($strategy->id, $customerId, videoIds: $selected);
            Cache::put($key, true, now()->addDay());

            return;
        }

        if ($videos->isNotEmpty()) {
            return;
        }

        GenerateVideo::dispatch($campaign, $strategy, 'Google Ads (Performance Max)', 0, true);
        AgentActivity::record(
            'maintenance', 'video_generation_started',
            "Generating a Performance Max video for '{$campaign->name}' to review",
            $campaign->customer_id, $campaign->id, []
        );
        Cache::put($key, true, now()->addDay());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RunSelfHealingChecks failed: '.$exception->getMessage());
        $this->recordRunFailure($exception);
    }
}
