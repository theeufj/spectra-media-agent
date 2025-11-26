<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use Illuminate\Support\Facades\Log;

class BudgetIntelligenceAgent
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('budget_rules', []);
    }

    /**
     * Apply intelligent budget adjustments to a campaign.
     *
     * @param Campaign $campaign
     * @return array Results of budget actions
     */
    public function optimize(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'adjustments' => [],
            'multiplier_applied' => 1.0,
            'errors' => [],
        ];

        if (!$campaign->google_ads_campaign_id || !$campaign->customer) {
            return $results;
        }

        // Calculate the combined multiplier
        $timeMultiplier = $this->getTimeOfDayMultiplier();
        $dayMultiplier = $this->getDayOfWeekMultiplier();
        $seasonalMultiplier = $this->getSeasonalMultiplier();

        // Seasonal overrides day-of-week if active
        $effectiveDayMultiplier = $seasonalMultiplier !== 1.0 ? $seasonalMultiplier : $dayMultiplier;

        $combinedMultiplier = $timeMultiplier * $effectiveDayMultiplier;
        $results['multiplier_applied'] = $combinedMultiplier;

        $results['adjustments'][] = [
            'type' => 'multiplier_calculation',
            'time_multiplier' => $timeMultiplier,
            'day_multiplier' => $dayMultiplier,
            'seasonal_multiplier' => $seasonalMultiplier,
            'combined' => $combinedMultiplier,
        ];

        // If multiplier is 1.0, no adjustment needed
        if ($combinedMultiplier === 1.0) {
            return $results;
        }

        // Calculate adjusted budget
        $baseDailyBudget = $campaign->daily_budget ?? 0;
        $adjustedBudget = $baseDailyBudget * $combinedMultiplier;
        $adjustedBudgetMicros = $adjustedBudget * 1000000;

        // Apply the budget change
        $customer = $campaign->customer;
        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

        try {
            $updateBudget = new UpdateCampaignBudget($customer, true);
            $success = ($updateBudget)($customerId, $campaignResourceName, $adjustedBudgetMicros);

            if ($success) {
                $results['adjustments'][] = [
                    'type' => 'budget_updated',
                    'base_budget' => $baseDailyBudget,
                    'adjusted_budget' => $adjustedBudget,
                    'reason' => $this->getAdjustmentReason($timeMultiplier, $effectiveDayMultiplier, $seasonalMultiplier),
                ];

                Log::info("BudgetIntelligenceAgent: Budget adjusted", [
                    'campaign_id' => $campaign->id,
                    'base' => $baseDailyBudget,
                    'adjusted' => $adjustedBudget,
                    'multiplier' => $combinedMultiplier,
                ]);
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Failed to update budget: " . $e->getMessage();
            Log::error("BudgetIntelligenceAgent: Failed to update budget", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Get the time-of-day multiplier based on current hour.
     */
    protected function getTimeOfDayMultiplier(): float
    {
        $multipliers = $this->config['time_of_day_multipliers'] ?? [];
        $currentHour = (int) now()->format('H');
        $currentTime = sprintf('%02d:00', $currentHour);

        foreach ($multipliers as $range => $multiplier) {
            [$start, $end] = explode('-', $range);
            
            if ($this->isTimeInRange($currentTime, $start, $end)) {
                return $multiplier;
            }
        }

        return 1.0;
    }

    /**
     * Check if a time is within a range.
     */
    protected function isTimeInRange(string $time, string $start, string $end): bool
    {
        if ($start <= $end) {
            return $time >= $start && $time < $end;
        } else {
            // Range crosses midnight (e.g., 21:00-00:00)
            return $time >= $start || $time < $end;
        }
    }

    /**
     * Get the day-of-week multiplier.
     */
    protected function getDayOfWeekMultiplier(): float
    {
        $multipliers = $this->config['day_of_week_multipliers'] ?? [];
        $currentDay = strtolower(now()->format('l'));

        return $multipliers[$currentDay] ?? 1.0;
    }

    /**
     * Get the seasonal multiplier if applicable.
     */
    protected function getSeasonalMultiplier(): float
    {
        $multipliers = $this->config['seasonal_multipliers'] ?? [];
        $today = now();

        // Check for specific dates (MM-DD format)
        $dateKey = $today->format('m-d');
        if (isset($multipliers[$dateKey])) {
            return $multipliers[$dateKey];
        }

        // Check for Black Friday (last Friday of November)
        if ($today->format('m') === '11') {
            $lastFriday = $today->copy()->lastOfMonth(\Carbon\Carbon::FRIDAY);
            if ($today->isSameDay($lastFriday) && isset($multipliers['black_friday'])) {
                return $multipliers['black_friday'];
            }
        }

        // Check for Cyber Monday (Monday after Black Friday)
        if ($today->format('m') === '11' || $today->format('m') === '12') {
            // Cyber Monday is the Monday after the last Thursday of November
            $thanksgiving = now()->setMonth(11)->lastOfMonth(\Carbon\Carbon::THURSDAY);
            $cyberMonday = $thanksgiving->copy()->addDays(4);
            if ($today->isSameDay($cyberMonday) && isset($multipliers['cyber_monday'])) {
                return $multipliers['cyber_monday'];
            }
        }

        return 1.0;
    }

    /**
     * Generate a human-readable reason for the adjustment.
     */
    protected function getAdjustmentReason(float $time, float $day, float $seasonal): string
    {
        $reasons = [];

        if ($time !== 1.0) {
            $reasons[] = "Time of day adjustment ({$time}x)";
        }

        if ($seasonal !== 1.0) {
            $reasons[] = "Seasonal event ({$seasonal}x)";
        } elseif ($day !== 1.0) {
            $dayName = ucfirst(now()->format('l'));
            $reasons[] = "{$dayName} adjustment ({$day}x)";
        }

        return implode(', ', $reasons) ?: 'Standard adjustment';
    }

    /**
     * Analyze cross-campaign budget reallocation opportunities.
     *
     * @param Customer $customer
     * @param array $campaigns Array of Campaign models
     * @return array Reallocation recommendations
     */
    public function analyzeReallocation(Customer $customer, array $campaigns): array
    {
        $reallocationRules = $this->config['reallocation_rules'] ?? [];
        $minRoas = $reallocationRules['min_roas_threshold'] ?? 1.5;
        $maxShift = $reallocationRules['max_shift_percentage'] ?? 20;
        $minDays = $reallocationRules['min_data_days'] ?? 7;
        $minConversions = $reallocationRules['min_conversions'] ?? 5;

        $recommendations = [];
        $performers = ['winners' => [], 'losers' => []];

        foreach ($campaigns as $campaign) {
            if (!$campaign->google_ads_campaign_id) {
                continue;
            }

            $customerId = $customer->google_ads_customer_id;
            $resourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

            try {
                $getPerformance = new GetCampaignPerformance($customer, true);
                $metrics = ($getPerformance)($customerId, $resourceName, 'LAST_30_DAYS');

                if (!$metrics) {
                    continue;
                }

                $cost = ($metrics['cost_micros'] ?? 0) / 1000000;
                $conversions = $metrics['conversions'] ?? 0;
                $roas = $cost > 0 ? ($conversions * 50) / $cost : 0; // Assuming $50 avg order value

                if ($conversions >= $minConversions) {
                    if ($roas >= $minRoas * 1.5) {
                        $performers['winners'][] = [
                            'campaign' => $campaign,
                            'roas' => $roas,
                            'cost' => $cost,
                            'conversions' => $conversions,
                        ];
                    } elseif ($roas < $minRoas) {
                        $performers['losers'][] = [
                            'campaign' => $campaign,
                            'roas' => $roas,
                            'cost' => $cost,
                            'conversions' => $conversions,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning("BudgetIntelligenceAgent: Could not analyze campaign", [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Generate recommendations
        if (!empty($performers['winners']) && !empty($performers['losers'])) {
            foreach ($performers['losers'] as $loser) {
                $shiftAmount = $loser['campaign']->daily_budget * ($maxShift / 100);
                
                $recommendations[] = [
                    'type' => 'budget_reallocation',
                    'from_campaign' => $loser['campaign']->name,
                    'from_campaign_id' => $loser['campaign']->id,
                    'from_roas' => round($loser['roas'], 2),
                    'to_campaign' => $performers['winners'][0]['campaign']->name,
                    'to_campaign_id' => $performers['winners'][0]['campaign']->id,
                    'to_roas' => round($performers['winners'][0]['roas'], 2),
                    'shift_amount' => $shiftAmount,
                    'reason' => "Shift budget from underperforming campaign (ROAS: {$loser['roas']}) to top performer",
                ];
            }
        }

        return $recommendations;
    }
}
