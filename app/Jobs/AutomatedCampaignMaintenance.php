<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\MaintenanceSummaryNotification;
use App\Services\Agents\SelfHealingAgent;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Agents\AdExtensionAgent;
use App\Services\Agents\BidAdjustmentAgent;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\Agents\CreativeIntelligenceAgent;
use App\Jobs\FindUnderperformingKeywords;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutomatedCampaignMaintenance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(
        SelfHealingAgent $selfHealingAgent,
        SearchTermMiningAgent $searchTermAgent,
        BudgetIntelligenceAgent $budgetAgent,
        CreativeIntelligenceAgent $creativeAgent,
        AdExtensionAgent $extensionAgent,
        BidAdjustmentAgent $bidAgent,
        QualityScoreImprovementAgent $qsAgent
    ): void {
        Log::info("AutomatedCampaignMaintenance: Starting daily maintenance run");

        // Per-customer digest: customer_id → [campaign_name => changes]
        $customerDigests = [];

        // Get all active campaigns (Google, Facebook, Microsoft, and LinkedIn)
        $campaigns = Campaign::with('customer')
            ->where('primary_status', 'ELIGIBLE')
            ->where(fn($q) => $q->whereNotNull('google_ads_campaign_id')
                                ->orWhereNotNull('facebook_ads_campaign_id')
                                ->orWhereNotNull('microsoft_ads_campaign_id')
                                ->orWhereNotNull('linkedin_campaign_id'))
            ->get();

        $summary = [
            'campaigns_processed' => 0,
            'google_campaigns' => 0,
            'facebook_campaigns' => 0,
            'microsoft_campaigns' => 0,
            'linkedin_campaigns' => 0,
            'healing_actions' => 0,
            'healing_warnings' => 0,
            'keywords_added' => 0,
            'negatives_added' => 0,
            'budget_adjustments' => 0,
            'creative_adjustments' => 0,
            'errors' => 0,
        ];

        foreach ($campaigns as $campaign) {
            // Skip if another worker is already processing this campaign
            $lock = Cache::lock("maintenance:campaign:{$campaign->id}", 3600);
            if (!$lock->get()) {
                Log::info("AutomatedCampaignMaintenance: Campaign {$campaign->id} already being processed, skipping");
                continue;
            }

            try {
                Log::info("AutomatedCampaignMaintenance: Processing campaign", [
                    'campaign_id' => $campaign->id,
                    'name' => $campaign->name,
                    'has_google' => !empty($campaign->google_ads_campaign_id),
                    'has_facebook' => !empty($campaign->facebook_ads_campaign_id),
                    'has_microsoft' => !empty($campaign->microsoft_ads_campaign_id),
                    'has_linkedin' => !empty($campaign->linkedin_campaign_id),
                ]);

                // Track platform
                if ($campaign->google_ads_campaign_id) {
                    $summary['google_campaigns']++;
                }
                if ($campaign->facebook_ads_campaign_id) {
                    $summary['facebook_campaigns']++;
                }
                if ($campaign->microsoft_ads_campaign_id) {
                    $summary['microsoft_campaigns']++;
                }
                if ($campaign->linkedin_campaign_id) {
                    $summary['linkedin_campaigns']++;
                }

                // 1. Run Self-Healing Agent (supports multiple platforms)
                $healingResults = $selfHealingAgent->heal($campaign);
                $summary['healing_actions'] += count($healingResults['actions_taken'] ?? []);
                $summary['healing_warnings'] += count($healingResults['warnings'] ?? []);
                $summary['errors'] += count($healingResults['errors'] ?? []);

                // 2. Run Search Term Mining (only for Google Search and Microsoft Search campaigns)
                $miningResults = ['keywords_added' => [], 'negatives_added' => [], 'errors' => []];
                if ($campaign->google_ads_campaign_id || $campaign->microsoft_ads_campaign_id) {
                    $miningResults = $searchTermAgent->mine($campaign);
                    $summary['keywords_added'] += count($miningResults['keywords_added'] ?? []);
                    $summary['negatives_added'] += count($miningResults['negatives_added'] ?? []);
                    $summary['errors'] += count($miningResults['errors'] ?? []);
                }

                // 2b. Negate underperforming keywords (Google only — stagger to avoid API rate limits)
                if ($campaign->google_ads_campaign_id) {
                    FindUnderperformingKeywords::dispatch($campaign->id)
                        ->delay(now()->addSeconds($summary['campaigns_processed'] * 5));
                }

                // 2c. Ad extension coverage + rotation (Google only)
                if ($campaign->google_ads_campaign_id) {
                    $extensionResults = $extensionAgent->manage($campaign);
                    $summary['creative_adjustments'] += count($extensionResults['created'] ?? []) + count($extensionResults['rotated'] ?? []);
                }

                // 2d. Bid adjustments by device and daypart (Google only)
                if ($campaign->google_ads_campaign_id) {
                    $bidResults = $bidAgent->optimize($campaign);
                    $summary['budget_adjustments'] += count($bidResults['adjustments'] ?? []);
                }

                // 2e. Quality Score improvement — diagnose and act on low-QS keywords (Google only)
                if ($campaign->google_ads_campaign_id) {
                    $qsResults = $qsAgent->improve($campaign);
                    $summary['keywords_added'] += count($qsResults['actions'] ?? []);
                }

                // 3. Run Budget Intelligence
                $budgetResults = $budgetAgent->optimize($campaign);
                $summary['budget_adjustments'] += count(array_filter(
                    $budgetResults['adjustments'] ?? [],
                    fn($a) => $a['type'] === 'budget_updated'
                ));
                $summary['errors'] += count($budgetResults['errors'] ?? []);

                // 4. Run Creative Intelligence
                $creativeResults = $creativeAgent->analyze($campaign);
                $summary['creative_adjustments'] += count($creativeResults['recommendations'] ?? []);
                // If it generated variations, that's an action
                $summary['creative_adjustments'] += count($creativeResults['generated_variations']['headlines'] ?? []);
                $summary['creative_adjustments'] += count($creativeResults['generated_variations']['descriptions'] ?? []);

                $summary['campaigns_processed']++;

                // Accumulate per-customer digest
                $customerId = $campaign->customer_id;
                $healed     = count($healingResults['actions_taken'] ?? []);
                $kwAdded    = count($miningResults['keywords_added'] ?? []);
                $negAdded   = count($miningResults['negatives_added'] ?? []);
                $budgetAdj  = count(array_filter($budgetResults['adjustments'] ?? [], fn($a) => ($a['type'] ?? '') === 'budget_updated'));
                $creative   = count($creativeResults['recommendations'] ?? [])
                            + count($creativeResults['generated_variations']['headlines'] ?? [])
                            + count($creativeResults['generated_variations']['descriptions'] ?? []);

                $customerDigests[$customerId][$campaign->name] = [
                    'total_changes'      => $healed + $kwAdded + $negAdded + $budgetAdj + $creative,
                    'healed'             => $healed ?: null,
                    'keywords_added'     => $kwAdded ?: null,
                    'negatives_added'    => $negAdded ?: null,
                    'budget_adjustments' => $budgetAdj ?: null,
                    'creative_adjustments' => $creative ?: null,
                ];

                // Store maintenance results on the campaign
                $campaign->update([
                    'last_maintenance_at' => now(),
                    'last_maintenance_results' => [
                        'healing' => $healingResults,
                        'mining' => $miningResults,
                        'budget' => $budgetResults,
                        'creative' => $creativeResults,
                    ],
                ]);

                // Log agent activities for user visibility
                $healingActionCount = count($healingResults['actions_taken'] ?? []);
                $keywordsAdded = count($miningResults['keywords_added'] ?? []);
                $negativesAdded = count($miningResults['negatives_added'] ?? []);
                $budgetChangeCount = count(array_filter($budgetResults['adjustments'] ?? [], fn($a) => $a['type'] === 'budget_updated'));
                $creativeGenCount = count($creativeResults['generated_variations']['headlines'] ?? []) + count($creativeResults['generated_variations']['descriptions'] ?? []);

                if ($healingActionCount > 0) {
                    AgentActivity::record('maintenance', 'self_healed', "Fixed {$healingActionCount} issue(s) in \"{$campaign->name}\"", $campaign->customer_id, $campaign->id, ['actions' => $healingResults['actions_taken'] ?? []]);
                }
                if ($keywordsAdded > 0 || $negativesAdded > 0) {
                    AgentActivity::record('keyword', 'mined_search_terms', "Added {$keywordsAdded} keyword(s) and {$negativesAdded} negative(s) for \"{$campaign->name}\"", $campaign->customer_id, $campaign->id);
                }
                if ($budgetChangeCount > 0) {
                    AgentActivity::record('budget', 'adjusted_budget', "Made {$budgetChangeCount} budget adjustment(s) for \"{$campaign->name}\"", $campaign->customer_id, $campaign->id, ['adjustments' => $budgetResults['adjustments'] ?? []]);
                }
                if ($creativeGenCount > 0) {
                    AgentActivity::record('creative', 'creative_optimized', "Generated {$creativeGenCount} new ad variations based on performance data for \"{$campaign->name}\"", $campaign->customer_id, $campaign->id);
                }

            } catch (\Exception $e) {
                $summary['errors']++;
                Log::error("AutomatedCampaignMaintenance: Failed to process campaign", [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }

        Log::info("AutomatedCampaignMaintenance: Completed daily maintenance", $summary);

        // Send one summary email per customer covering all their campaigns
        foreach ($customerDigests as $customerId => $campaignChanges) {
            $customer = \App\Models\Customer::find($customerId);
            if (!$customer) {
                continue;
            }
            $processed = count($campaignChanges);
            $user = $customer->users()->first();
            if ($user) {
                $user->notify(new MaintenanceSummaryNotification($campaignChanges, $processed));
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('AutomatedCampaignMaintenance failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
