<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Recommendation;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent;
use App\Services\Agents\LinkedInCampaignOptimizationAgent;
use App\Services\Agents\MicrosoftAdsCampaignOptimizationAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OptimizeCampaigns implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(CampaignOptimizationAgent $optimizationAgent, FacebookAdRelevanceDiagnosticsAgent $fbDiagnostics): void
    {
        $runStart = $this->startRun();
        $totalApplied = 0;
        $errors = 0;

        // Campaigns the platform reports as actually delivering. Campaign::serving()
        // owns that list: LIMITED counts alongside ELIGIBLE and LEARNING, and these
        // queries used to spell out ['ELIGIBLE', 'LEARNING'] by hand and drop it —
        // so a campaign limited by its budget was skipped by the budget optimiser.
        $campaigns = Campaign::serving()
            ->where(function ($query) {
                $query->whereNotNull('google_ads_campaign_id')
                    ->orWhereNotNull('facebook_ads_campaign_id')
                    ->orWhereNotNull('microsoft_ads_campaign_id');
            })
            ->where(function ($query) {
                $query->whereNull('last_optimized_at')
                    ->orWhere('last_optimized_at', '<=', now()->subHours(24));
            })
            ->get();

        // Filter by plan-aware optimization frequency:
        // Free / Starter → weekly (7 days), Growth / Agency → daily (24h)
        $campaigns = $campaigns->filter(function (Campaign $campaign) {
            if (is_null($campaign->last_optimized_at)) {
                return true; // never optimized — always run
            }

            // How often an account is re-optimised is a property of its plan,
            // not of whichever of its people the query happened to return.
            $slug = $campaign->customer?->resolvePlan()->slug ?? 'free';

            $cooldown = in_array($slug, ['growth', 'agency'], true)
                ? now()->subHours(24)
                : now()->subDays(7);

            return $campaign->last_optimized_at <= $cooldown;
        });

        foreach ($campaigns as $campaign) {
            try {
                Log::info("Starting optimization for campaign {$campaign->id} ({$campaign->name})");

                $recommendations = $optimizationAgent->analyze($campaign);

                $appliedCount = 0;
                $pendingCount = 0;

                if ($recommendations) {
                    // Clear old pending recommendations for this campaign
                    Recommendation::where('campaign_id', $campaign->id)
                        ->where('status', 'pending')
                        ->delete();

                    $categorized = $recommendations['categorized'] ?? [];
                    $autoApply = $categorized['auto_apply'] ?? [];
                    $needsReview = array_merge(
                        $categorized['recommended'] ?? [],
                        $categorized['review_required'] ?? []
                    );

                    $platform = $campaign->google_ads_campaign_id ? 'google'
                        : ($campaign->facebook_ads_campaign_id ? 'facebook' : 'microsoft');

                    // Auto-apply high-confidence recommendations immediately
                    foreach ($autoApply as $rec) {
                        $result = $optimizationAgent->applyRecommendation($campaign, $rec);

                        Recommendation::create([
                            'campaign_id' => $campaign->id,
                            'type' => $rec['type'] ?? 'general',
                            'target_entity' => $rec['target_entity'] ?? $rec['target'] ?? null,
                            'parameters' => $rec['parameters'] ?? $rec['params'] ?? null,
                            'rationale' => $rec['rationale'] ?? $rec['reason'] ?? $rec['description'] ?? null,
                            'status' => $result['applied'] ? 'applied' : 'failed',
                            'requires_approval' => false,
                            'platform' => $platform,
                        ]);

                        if ($result['applied']) {
                            $appliedCount++;
                        }
                    }

                    // Store lower-confidence recommendations for human review
                    foreach ($needsReview as $rec) {
                        /*
                         * Not the same suggestion twice.
                         *
                         * This runs nightly and re-derives its recommendations
                         * from the same performance data, so a campaign nobody
                         * has triaged for a fortnight collected fourteen copies
                         * of the same advice. The list a human is meant to make
                         * decisions from became the list they scroll past.
                         */
                        $type = $rec['type'] ?? 'general';
                        $target = $rec['target_entity'] ?? $rec['target'] ?? null;

                        if (Recommendation::alreadyPending($campaign->id, $type, is_array($target) ? $target : null)) {
                            continue;
                        }

                        Recommendation::create([
                            'campaign_id' => $campaign->id,
                            'type' => $rec['type'] ?? 'general',
                            'target_entity' => $rec['target_entity'] ?? $rec['target'] ?? null,
                            'parameters' => $rec['parameters'] ?? $rec['params'] ?? null,
                            'rationale' => $rec['rationale'] ?? $rec['reason'] ?? $rec['description'] ?? null,
                            'status' => 'pending',
                            'requires_approval' => true,
                            'platform' => $platform,
                        ]);
                        $pendingCount++;
                    }

                    $campaign->update(['latest_optimization_analysis' => $recommendations]);

                    AgentActivity::record(
                        'optimization',
                        'analyzed_campaign',
                        "Optimised \"{$campaign->name}\": {$appliedCount} changes applied automatically, {$pendingCount} queued for review",
                        $campaign->customer_id,
                        $campaign->id,
                        ['auto_applied' => $appliedCount, 'pending_review' => $pendingCount]
                    );
                }

                // Always stamp last_optimized_at so this campaign isn't re-queued every run
                // when there's insufficient data for recommendations (e.g. brand-new campaigns).
                $campaign->update(['last_optimized_at' => now()]);

                $totalApplied += $appliedCount;

                Log::info("Optimization complete for campaign {$campaign->id}", [
                    'auto_applied' => $appliedCount,
                    'pending_review' => $pendingCount,
                    'had_data' => $recommendations !== null,
                ]);

                // Run Facebook ad relevance diagnostics for Facebook campaigns
                if ($campaign->facebook_ads_campaign_id) {
                    try {
                        $fbDiagnostics->analyze($campaign);
                    } catch (\Throwable $e) {
                        // Surface in the admin exception dashboard; the batch continues.
                        report($e);
                        Log::warning("FacebookAdRelevanceDiagnosticsAgent failed for campaign {$campaign->id}: ".$e->getMessage());
                    }
                }

                // LinkedIn-specific optimization
                if ($campaign->linkedin_campaign_id) {
                    try {
                        (new LinkedInCampaignOptimizationAgent)->analyze($campaign);
                    } catch (\Throwable $e) {
                        // Surface in the admin exception dashboard; the batch continues.
                        report($e);
                        Log::warning("LinkedInCampaignOptimizationAgent failed for campaign {$campaign->id}: ".$e->getMessage());
                    }
                }

                // Microsoft Ads-specific optimization
                if ($campaign->microsoft_ads_campaign_id) {
                    try {
                        (new MicrosoftAdsCampaignOptimizationAgent)->analyze($campaign);
                    } catch (\Throwable $e) {
                        // Surface in the admin exception dashboard; the batch continues.
                        report($e);
                        Log::warning("MicrosoftAdsCampaignOptimizationAgent failed for campaign {$campaign->id}: ".$e->getMessage());
                    }
                }

            } catch (\Throwable $e) {
                // Surface in the admin exception dashboard; the batch continues.
                report($e);
                $errors++;
                Log::error("Failed to optimize campaign {$campaign->id}: ".$e->getMessage(), [
                    'campaign_id' => $campaign->id,
                    'exception' => get_class($e),
                ]);
                // Continue to next campaign — don't let one failure block others
            }
        }

        $this->finishRun($runStart, actions: $totalApplied, errors: $errors, scope: $campaigns->count().' campaigns');
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('OptimizeCampaigns failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
        $this->recordRunFailure($exception);
    }
}
