<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Agents\SelfHealingAgent;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\Agents\BudgetIntelligenceAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        BudgetIntelligenceAgent $budgetAgent
    ): void {
        Log::info("AutomatedCampaignMaintenance: Starting daily maintenance run");

        // Get all active campaigns
        $campaigns = Campaign::with('customer')
            ->where('primary_status', 'ELIGIBLE')
            ->whereNotNull('google_ads_campaign_id')
            ->get();

        $summary = [
            'campaigns_processed' => 0,
            'healing_actions' => 0,
            'keywords_added' => 0,
            'negatives_added' => 0,
            'budget_adjustments' => 0,
            'errors' => 0,
        ];

        foreach ($campaigns as $campaign) {
            try {
                Log::info("AutomatedCampaignMaintenance: Processing campaign", [
                    'campaign_id' => $campaign->id,
                    'name' => $campaign->name,
                ]);

                // 1. Run Self-Healing Agent
                $healingResults = $selfHealingAgent->heal($campaign);
                $summary['healing_actions'] += count($healingResults['actions_taken'] ?? []);
                $summary['errors'] += count($healingResults['errors'] ?? []);

                // 2. Run Search Term Mining (only for Search campaigns)
                // We could check campaign type here, but for now we'll try on all
                $miningResults = $searchTermAgent->mine($campaign);
                $summary['keywords_added'] += count($miningResults['keywords_added'] ?? []);
                $summary['negatives_added'] += count($miningResults['negatives_added'] ?? []);
                $summary['errors'] += count($miningResults['errors'] ?? []);

                // 3. Run Budget Intelligence
                $budgetResults = $budgetAgent->optimize($campaign);
                $summary['budget_adjustments'] += count(array_filter(
                    $budgetResults['adjustments'] ?? [],
                    fn($a) => $a['type'] === 'budget_updated'
                ));
                $summary['errors'] += count($budgetResults['errors'] ?? []);

                $summary['campaigns_processed']++;

                // Store maintenance results on the campaign
                $campaign->update([
                    'last_maintenance_at' => now(),
                    'last_maintenance_results' => [
                        'healing' => $healingResults,
                        'mining' => $miningResults,
                        'budget' => $budgetResults,
                    ],
                ]);

            } catch (\Exception $e) {
                $summary['errors']++;
                Log::error("AutomatedCampaignMaintenance: Failed to process campaign", [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("AutomatedCampaignMaintenance: Completed daily maintenance", $summary);
    }
}
