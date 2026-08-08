<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class BudgetAllocationService
{
    private const DEFAULT_ROAS = 0.5; // Return on Ad Spend

    private const MIN_BUDGET_PERCENTAGE = 0.05; // Minimum 5% of total budget

    public function __invoke(Customer $customer, float $totalBudget): bool
    {
        try {
            $campaigns = $customer->campaigns()
                ->where('status', CampaignStatus::Active)
                ->with('strategies.performanceData')
                ->get();

            if ($campaigns->isEmpty()) {
                Log::warning("No active campaigns found for customer {$customer->id} to allocate budget to.");

                return false;
            }

            $campaignPerformance = $this->calculateCampaignPerformance($campaigns);
            $totalWeightedPerformance = array_sum($campaignPerformance);

            if ($totalWeightedPerformance === 0) {
                // If no performance data, distribute budget evenly
                return $this->distributeEvenly($campaigns, $totalBudget);
            }

            $this->allocateBudgetByPerformance($campaigns, $campaignPerformance, $totalWeightedPerformance, $totalBudget);

            return true;
        } catch (\Exception $e) {
            Log::error("Error allocating budget for customer {$customer->id}: ".$e->getMessage(), [
                'exception' => $e,
            ]);

            return false;
        }
    }

    private function calculateCampaignPerformance($campaigns): array
    {
        $campaignPerformance = [];
        foreach ($campaigns as $campaign) {
            $totalRoas = 0;
            $strategyCount = $campaign->strategies->count();

            if ($strategyCount > 0) {
                foreach ($campaign->strategies as $strategy) {
                    $performanceData = $strategy->performanceData->first();
                    if ($performanceData && $performanceData->spend > 0) {
                        // cpa_target is stored in micros (GenerateStrategy writes
                        // targetCpaMicros) while spend is in dollars. Multiplying the
                        // raw value produced a ROAS ~1,000,000x too large, so any
                        // campaign with performance data buried every campaign that
                        // fell back to DEFAULT_ROAS (0.5) in the weighting below.
                        // Mirrors PortfolioOptimizationService, which gets this right.
                        $cpaInDollars = ($strategy->cpa_target ?? 0) / 1_000_000;
                        $revenueMultiple = $strategy->revenue_cpa_multiple ?? 1.0;
                        $revenue = $performanceData->conversions * $cpaInDollars * $revenueMultiple;
                        $totalRoas += $revenue / $performanceData->spend;
                    } else {
                        $totalRoas += self::DEFAULT_ROAS;
                    }
                }
                $campaignPerformance[$campaign->id] = $totalRoas / $strategyCount;
            } else {
                $campaignPerformance[$campaign->id] = self::DEFAULT_ROAS;
            }
        }

        return $campaignPerformance;
    }

    private function allocateBudgetByPerformance($campaigns, $campaignPerformance, $totalWeightedPerformance, $totalBudget): void
    {
        $minBudget = $totalBudget * self::MIN_BUDGET_PERCENTAGE;
        $performanceBasedBudget = $totalBudget * (1 - (count($campaigns) * self::MIN_BUDGET_PERCENTAGE));

        foreach ($campaigns as $campaign) {
            $performanceWeight = $campaignPerformance[$campaign->id] / $totalWeightedPerformance;
            $allocatedBudget = $minBudget + ($performanceBasedBudget * $performanceWeight);

            $campaign->update(['daily_budget' => $allocatedBudget]);

            Log::info("Allocating budget of {$allocatedBudget} to campaign {$campaign->id}.", [
                'customer_id' => $campaign->customer_id,
                'campaign_id' => $campaign->id,
                'budget' => $allocatedBudget,
                'performance_weight' => $performanceWeight,
            ]);
        }
    }

    private function distributeEvenly($campaigns, $totalBudget): bool
    {
        $campaignCount = $campaigns->count();
        if ($campaignCount === 0) {
            return false;
        }
        $budgetPerCampaign = $totalBudget / $campaignCount;

        foreach ($campaigns as $campaign) {
            $campaign->update(['daily_budget' => $budgetPerCampaign]);
            Log::info("Allocating budget of {$budgetPerCampaign} to campaign {$campaign->id} (evenly distributed).", [
                'customer_id' => $campaign->customer_id,
                'campaign_id' => $campaign->id,
                'budget' => $budgetPerCampaign,
            ]);
        }

        return true;
    }
}
