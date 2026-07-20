<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use App\Jobs\Concerns\RecordsAgentRun;
use App\Services\Agents\SelfHealingAgent;
use App\Services\Agents\FacebookLearningPhaseAgent;
use App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent;
use App\Services\Agents\LinkedInCampaignOptimizationAgent;
use App\Models\Recommendation;
use App\Services\GoogleAds\CommonServices\CreateSitelinkAssets;
use App\Services\GoogleAds\CommonServices\VerifyConversionGoals;
use App\Services\GoogleAds\PerformanceMaxServices\HealAssetGroupStrength;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs every 4 hours. Scans all live campaigns for disapproved ads, rewrites
 * them, and resubmits — without running the heavier daily maintenance tasks
 * (keyword mining, bid adjustments, extensions, etc.).
 */
class RunSelfHealingChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RecordsAgentRun;

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
            ->whereIn('primary_status', ['ELIGIBLE', 'LEARNING'])
            ->where(fn($q) => $q->whereNotNull('google_ads_campaign_id')
                                ->orWhereNotNull('facebook_ads_campaign_id')
                                ->orWhereNotNull('microsoft_ads_campaign_id')
                                ->orWhereNotNull('linkedin_campaign_id'))
            ->get();

        $healed = 0;
        $errors = 0;
        $conversionCheckedCustomers = []; // conversion-goal hygiene runs once per customer per pass

        foreach ($campaigns as $campaign) {
            $lock = Cache::lock("self_heal:campaign:{$campaign->id}", 3600);
            if (!$lock->get()) {
                continue;
            }

            try {
                // Pass 1: existing healing (disapproved ads, budget, delivery)
                $results = $selfHealingAgent->heal($campaign);
                $healed += count($results['actions_taken'] ?? []);
                $errors += count($results['errors'] ?? []);

                // Pass 2: strategic diagnosis → autonomous remediation
                $findings = $diagnosticsAgent->diagnose($campaign);
                if (!empty($findings)) {
                    Log::info('RunSelfHealingChecks: Diagnostic findings for campaign ' . $campaign->id, [
                        'count'    => count($findings),
                        'types'    => array_column($findings, 'type'),
                    ]);
                    $remediationResult = $remediationAgent->remediate($campaign, $findings);
                    $healed += count($remediationResult['actions_taken'] ?? []);
                    $errors += count($remediationResult['errors'] ?? []);
                }

                if ($campaign->facebook_ads_campaign_id) {
                    $fbLearningAgent->analyze($campaign);
                    if (!FacebookLearningPhaseAgent::isOnHold($campaign)) {
                        $fbRelevanceAgent->analyze($campaign);
                    }
                }

                if ($campaign->linkedin_campaign_id) {
                    $linkedInAgent->analyze($campaign);
                }

                // Pass 3 (Google): heal POOR/AVERAGE PMax ad strength + conversion-goal hygiene.
                if ($campaign->google_ads_campaign_id) {
                    try {
                        foreach ((new HealAssetGroupStrength($campaign->customer))->heal($campaign) as $r) {
                            $addedCount = array_sum($r['added'] ?? []);
                            if ($addedCount > 0) {
                                $healed += $addedCount;
                                $imgCount  = $r['added']['IMAGE'] ?? 0;
                                $textCount = $addedCount - $imgCount;
                                $parts = [];
                                if ($textCount > 0) $parts[] = "{$textCount} text asset(s)";
                                if ($imgCount > 0)  $parts[] = "{$imgCount} image(s)";
                                $what = implode(' + ', $parts);

                                Recommendation::create([
                                    'campaign_id'       => $campaign->id,
                                    'type'              => 'AD_STRENGTH',
                                    'rationale'         => "Added {$what} to '{$r['asset_group']}' (ad strength was {$r['ad_strength']})",
                                    'status'            => 'applied',
                                    'requires_approval' => false,
                                ]);
                                AgentActivity::record(
                                    'maintenance', 'ad_strength_healed',
                                    "Added {$what} to '{$r['asset_group']}' (ad strength was {$r['ad_strength']})",
                                    $campaign->customer_id, $campaign->id, $r
                                );
                            }
                        }

                        // Ensure the campaign has sitelinks (improves ad strength + real estate).
                        $sitelinksAdded = (new CreateSitelinkAssets($campaign->customer))->heal($campaign);
                        if ($sitelinksAdded > 0) {
                            $healed += $sitelinksAdded;
                            Recommendation::create([
                                'campaign_id'       => $campaign->id,
                                'type'              => 'SITELINKS',
                                'rationale'         => "Added {$sitelinksAdded} sitelink(s) to improve ad strength",
                                'status'            => 'applied',
                                'requires_approval' => false,
                            ]);
                            AgentActivity::record(
                                'maintenance', 'sitelinks_added',
                                "Added {$sitelinksAdded} sitelink(s) to '{$campaign->name}'",
                                $campaign->customer_id, $campaign->id, []
                            );
                        }

                        // Conversion-goal hygiene — once per customer per pass.
                        if (!in_array($campaign->customer_id, $conversionCheckedCustomers, true)) {
                            $conversionCheckedCustomers[] = $campaign->customer_id;
                            $conv = (new VerifyConversionGoals($campaign->customer))->verifyAndHeal();
                            foreach (($conv['actions'] ?? []) as $action) {
                                $healed++;
                                AgentActivity::record('maintenance', 'conversion_goal_fixed', $action, $campaign->customer_id, $campaign->id, []);
                            }
                            foreach (($conv['warnings'] ?? []) as $warning) {
                                Log::warning("RunSelfHealingChecks: conversion goal warning (customer {$campaign->customer_id}): {$warning}");
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::error('RunSelfHealingChecks: Google strength/conversion pass failed for campaign ' . $campaign->id . ': ' . $e->getMessage());
                        $errors++;
                    }
                }

                if (!empty($results['actions_taken'])) {
                    AgentActivity::record(
                        'maintenance',
                        'self_healed',
                        'Fixed ' . count($results['actions_taken']) . ' issue(s) in "' . $campaign->name . '"',
                        $campaign->customer_id,
                        $campaign->id,
                        ['actions' => $results['actions_taken']]
                    );
                }
            } catch (\Exception $e) {
                Log::error('RunSelfHealingChecks: Error processing campaign ' . $campaign->id . ': ' . $e->getMessage());
                $errors++;
            } finally {
                $lock->release();
            }
        }

        Log::info('RunSelfHealingChecks: Completed', [
            'campaigns' => $campaigns->count(),
            'healed'    => $healed,
            'errors'    => $errors,
        ]);

        $this->finishRun($runStart, actions: $healed, errors: $errors, scope: $campaigns->count() . ' campaigns');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RunSelfHealingChecks failed: ' . $exception->getMessage());
        $this->recordRunFailure($exception);
    }
}
