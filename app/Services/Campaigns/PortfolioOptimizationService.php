<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Recommendation;
use Illuminate\Support\Facades\Log;

class PortfolioOptimizationService
{
    private const ROAS_PAUSE_THRESHOLD = 0.8;
    private const ROAS_INCREASE_BUDGET_THRESHOLD = 2.5;

    public function __invoke(Customer $customer): bool
    {
        try {
            $campaigns = $customer->campaigns()
                ->where('status', 'ACTIVE')
                ->with('strategies.performanceData')
                ->get();

            if ($campaigns->isEmpty()) {
                Log::info("No active campaigns to optimize for customer {$customer->id}.");
                return true;
            }

            foreach ($campaigns as $campaign) {
                $campaignRoas = $this->calculateCampaignRoas($campaign);

                if ($campaignRoas < self::ROAS_PAUSE_THRESHOLD) {
                    $this->createPauseRecommendation($campaign, $campaignRoas);
                } elseif ($campaignRoas > self::ROAS_INCREASE_BUDGET_THRESHOLD) {
                    $this->createIncreaseBudgetRecommendation($campaign, $campaignRoas);
                }
            }

            Log::info("Portfolio optimization check completed for customer {$customer->id}.");
            return true;

        } catch (\Exception $e) {
            Log::error("Error optimizing portfolio for customer {$customer->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false;
        }
    }

    private function calculateCampaignRoas(Campaign $campaign): float
    {
        $totalRevenue = 0;
        $totalSpend = 0;

        foreach ($campaign->strategies as $strategy) {
            $performanceData = $strategy->performanceData->first();
            if ($performanceData) {
                // Use the AI-generated revenue multiple to estimate revenue
                $revenueMultiple = $strategy->revenue_cpa_multiple ?? 1.0;
                $cpaInDollars = ($strategy->cpa_target ?? 0) / 1000000; // Convert from micros
                $totalRevenue += $performanceData->conversions * $cpaInDollars * $revenueMultiple;
                $totalSpend += $performanceData->spend;
            }
        }

        if ($totalSpend == 0) {
            return 0;
        }

        return $totalRevenue / $totalSpend;
    }

    private function createPauseRecommendation(Campaign $campaign, float $roas): void
    {
        Recommendation::updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'type' => 'PAUSE_CAMPAIGN',
                'status' => 'pending',
            ],
            [
                'target_entity' => ['campaign_id' => $campaign->id],
                'parameters' => ['new_status' => 'PAUSED'],
                'rationale' => "Campaign has a low ROAS of " . round($roas, 2) . ". Consider pausing to re-evaluate.",
                'requires_approval' => true,
            ]
        );
        Log::info("Created PAUSE_CAMPAIGN recommendation for campaign {$campaign->id}.");
    }

    private function createIncreaseBudgetRecommendation(Campaign $campaign, float $roas): void
    {
        Recommendation::updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'type' => 'INCREASE_BUDGET',
                'status' => 'pending',
            ],
            [
                'target_entity' => ['campaign_id' => $campaign->id],
                'parameters' => ['increase_percentage' => 20],
                'rationale' => "Campaign is performing well with a high ROAS of " . round($roas, 2) . ". Consider increasing the budget to scale.",
                'requires_approval' => true,
            ]
        );
        Log::info("Created INCREASE_BUDGET recommendation for campaign {$campaign->id}.");
    }
}
