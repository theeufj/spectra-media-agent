<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CampaignHourlyPerformance;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use App\Services\Agents\AdaptiveThresholds;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\FacebookAds\AdSetService as FacebookAdSetService;
use App\Services\FacebookAds\InsightService as FacebookInsightService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetIntelligenceAgent
{
    protected array $config;
    protected ?array $learnedHourlyMultipliers = null;
    protected ?array $learnedDayMultipliers = null;

    public function __construct()
    {
        $this->config = config('budget_rules', []);
    }

    /**
     * Apply intelligent budget adjustments to a campaign.
     * Supports Google Ads and Facebook Ads platforms.
     *
     * @param Campaign $campaign
     * @return array Results of budget actions
     */
    public function optimize(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'platform' => null,
            'adjustments' => [],
            'multiplier_applied' => 1.0,
            'errors' => [],
        ];

        if (!$campaign->customer) {
            return $results;
        }

        $hasGoogle    = $campaign->google_ads_campaign_id    && $campaign->customer->google_ads_customer_id;
        $hasFacebook  = $campaign->facebook_ads_campaign_id  && $campaign->customer->facebook_ads_account_id;
        $hasMicrosoft = $campaign->microsoft_ads_campaign_id && $campaign->customer->microsoft_ads_account_id;
        $hasLinkedIn  = $campaign->linkedin_campaign_id       && $campaign->customer->linkedin_ads_account_id;

        if (!$hasGoogle && !$hasFacebook && !$hasMicrosoft && !$hasLinkedIn) {
            return $results;
        }

        // Calculate the combined multiplier — prefer learned per-account curves over static config
        $customerId = $campaign->customer->id;
        $this->learnedHourlyMultipliers = CampaignHourlyPerformance::getLearnedHourlyMultipliers($customerId);
        $this->learnedDayMultipliers = CampaignHourlyPerformance::getLearnedDayMultipliers($customerId);

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
            'source' => $this->learnedHourlyMultipliers ? 'learned' : 'static',
        ];

        // If multiplier is 1.0, no adjustment needed
        if ($combinedMultiplier === 1.0) {
            return $results;
        }

        // Optimize Google Ads campaign
        if ($hasGoogle) {
            $results['platform'] = 'google_ads';
            $this->optimizeGoogleAdsCampaign($campaign, $combinedMultiplier, $timeMultiplier, $effectiveDayMultiplier, $seasonalMultiplier, $results);
        }

        // Optimize Facebook Ads campaign
        if ($hasFacebook) {
            $results['platform'] = $hasGoogle ? 'multi_platform' : 'facebook_ads';
            $this->optimizeFacebookAdsCampaign($campaign, $combinedMultiplier, $timeMultiplier, $effectiveDayMultiplier, $seasonalMultiplier, $results);
        }

        // Optimize Microsoft Ads campaign
        if ($hasMicrosoft) {
            $results['platform'] = ($hasGoogle || $hasFacebook) ? 'multi_platform' : 'microsoft_ads';
            $this->optimizeMicrosoftAdsCampaign($campaign, $combinedMultiplier, $results);
        }

        // Optimize LinkedIn Ads campaign
        if ($hasLinkedIn) {
            $results['platform'] = ($hasGoogle || $hasFacebook || $hasMicrosoft) ? 'multi_platform' : 'linkedin_ads';
            $this->optimizeLinkedInAdsCampaign($campaign, $combinedMultiplier, $results);
        }

        return $results;
    }

    protected function optimizeMicrosoftAdsCampaign(Campaign $campaign, float $combinedMultiplier, array &$results): void
    {
        $baseDailyBudget = $campaign->daily_budget ?? 0;
        if ($baseDailyBudget <= 0) {
            return;
        }

        $adjustedBudget = round($baseDailyBudget * $combinedMultiplier, 2);

        try {
            $service = new \App\Services\MicrosoftAds\CampaignService($campaign->customer);
            $ok = $service->updateBudget($campaign->microsoft_ads_campaign_id, $adjustedBudget);

            if ($ok) {
                $results['adjustments'][] = [
                    'type'           => 'budget_updated',
                    'platform'       => 'microsoft_ads',
                    'base_budget'    => $baseDailyBudget,
                    'adjusted_budget' => $adjustedBudget,
                    'multiplier'     => $combinedMultiplier,
                ];
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Microsoft Ads budget update failed: " . $e->getMessage();
        }
    }

    protected function optimizeLinkedInAdsCampaign(Campaign $campaign, float $combinedMultiplier, array &$results): void
    {
        $baseDailyBudget = $campaign->daily_budget ?? 0;
        if ($baseDailyBudget <= 0) {
            return;
        }

        // LinkedIn minimum daily budget is $10
        $adjustedBudget = max(10.0, round($baseDailyBudget * $combinedMultiplier, 2));

        try {
            $service = new \App\Services\LinkedInAds\CampaignService($campaign->customer);
            $ok = $service->updateBudget($campaign->linkedin_campaign_id, $adjustedBudget);

            if ($ok) {
                $results['adjustments'][] = [
                    'type'           => 'budget_updated',
                    'platform'       => 'linkedin_ads',
                    'base_budget'    => $baseDailyBudget,
                    'adjusted_budget' => $adjustedBudget,
                    'multiplier'     => $combinedMultiplier,
                ];
            }
        } catch (\Exception $e) {
            $results['errors'][] = "LinkedIn Ads budget update failed: " . $e->getMessage();
        }
    }

    /**
     * Apply budget adjustment to a Google Ads campaign.
     */
    protected function optimizeGoogleAdsCampaign(
        Campaign $campaign,
        float $combinedMultiplier,
        float $timeMultiplier,
        float $effectiveDayMultiplier,
        float $seasonalMultiplier,
        array &$results
    ): void {
        $baseDailyBudget = $campaign->daily_budget ?? 0;
        $adjustedBudget = $baseDailyBudget * $combinedMultiplier;
        $adjustedBudgetMicros = (int) round($adjustedBudget * 1_000_000);
        $adjustedBudgetMicros = (int) (round($adjustedBudgetMicros / 10_000) * 10_000);

        $customer = $campaign->customer;
        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

        try {
            $updateBudget = new UpdateCampaignBudget($customer, true);
            $success = ($updateBudget)($customerId, $campaignResourceName, $adjustedBudgetMicros);

            if ($success) {
                $results['adjustments'][] = [
                    'type' => 'budget_updated',
                    'platform' => 'google_ads',
                    'base_budget' => $baseDailyBudget,
                    'adjusted_budget' => $adjustedBudget,
                    'reason' => $this->getAdjustmentReason($timeMultiplier, $effectiveDayMultiplier, $seasonalMultiplier),
                ];

                Log::info("BudgetIntelligenceAgent: Google Ads budget adjusted", [
                    'campaign_id' => $campaign->id,
                    'base' => $baseDailyBudget,
                    'adjusted' => $adjustedBudget,
                    'multiplier' => $combinedMultiplier,
                ]);
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Google Ads: Failed to update budget: " . $e->getMessage();
            Log::error("BudgetIntelligenceAgent: Failed to update Google Ads budget", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply budget adjustment to Facebook Ads campaign ad sets.
     * Facebook budgets are managed at the ad set level (or campaign level with CBO).
     */
    protected function optimizeFacebookAdsCampaign(
        Campaign $campaign,
        float $combinedMultiplier,
        float $timeMultiplier,
        float $effectiveDayMultiplier,
        float $seasonalMultiplier,
        array &$results
    ): void {
        $customer = $campaign->customer;

        try {
            $adSetService = new FacebookAdSetService($customer);
            $adSets = $adSetService->listAdSets($campaign->facebook_ads_campaign_id);

            if (empty($adSets)) {
                $results['adjustments'][] = [
                    'type' => 'facebook_no_adsets',
                    'platform' => 'facebook_ads',
                    'message' => 'No ad sets found for Facebook campaign (may be using CBO)',
                ];
                return;
            }

            foreach ($adSets as $adSet) {
                $adSetId = $adSet['id'];
                $currentBudget = ($adSet['daily_budget'] ?? 0) / 100; // Facebook stores budget in cents

                if ($currentBudget <= 0) {
                    // Ad set may use lifetime budget or campaign-level CBO — skip
                    continue;
                }

                $baseDailyBudget = $campaign->daily_budget ?? $currentBudget;
                $adjustedBudget = $baseDailyBudget * $combinedMultiplier;

                // Facebook enforces minimum $5/day
                $adjustedBudget = max(5.0, $adjustedBudget);
                $adjustedBudgetCents = (int) round($adjustedBudget * 100);

                $success = $adSetService->updateAdSet($adSetId, [
                    'daily_budget' => $adjustedBudgetCents,
                ]);

                if ($success) {
                    $results['adjustments'][] = [
                        'type' => 'budget_updated',
                        'platform' => 'facebook_ads',
                        'adset_id' => $adSetId,
                        'adset_name' => $adSet['name'] ?? $adSetId,
                        'base_budget' => $baseDailyBudget,
                        'adjusted_budget' => $adjustedBudget,
                        'reason' => $this->getAdjustmentReason($timeMultiplier, $effectiveDayMultiplier, $seasonalMultiplier),
                    ];

                    Log::info("BudgetIntelligenceAgent: Facebook ad set budget adjusted", [
                        'campaign_id' => $campaign->id,
                        'adset_id' => $adSetId,
                        'base' => $baseDailyBudget,
                        'adjusted' => $adjustedBudget,
                        'multiplier' => $combinedMultiplier,
                    ]);
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Facebook Ads: Failed to update budget: " . $e->getMessage();
            Log::error("BudgetIntelligenceAgent: Failed to update Facebook Ads budget", [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the time-of-day multiplier based on current hour.
     * Prefers learned per-account curves when available, falls back to config defaults.
     */
    protected function getTimeOfDayMultiplier(): float
    {
        $currentHour = (int) now()->format('H');

        // Use learned multipliers if available
        if ($this->learnedHourlyMultipliers && isset($this->learnedHourlyMultipliers[$currentHour])) {
            return $this->learnedHourlyMultipliers[$currentHour];
        }

        // Fall back to config-based multipliers
        $multipliers = $this->config['time_of_day_multipliers'] ?? [];
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
     * Prefers learned per-account curves when available, falls back to config defaults.
     */
    protected function getDayOfWeekMultiplier(): float
    {
        $currentDay = strtolower(now()->format('l'));

        // Use learned multipliers if available
        if ($this->learnedDayMultipliers && isset($this->learnedDayMultipliers[$currentDay])) {
            return $this->learnedDayMultipliers[$currentDay];
        }

        // Fall back to config-based multipliers
        $multipliers = $this->config['day_of_week_multipliers'] ?? [];

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
     * Supports both Google Ads and Facebook Ads campaigns.
     *
     * @param Customer $customer
     * @param array $campaigns Array of Campaign models
     * @return array Reallocation recommendations
     */
    public function analyzeReallocation(Customer $customer, array $campaigns): array
    {
        $reallocationRules = $this->config['reallocation_rules'] ?? [];

        // Use adaptive ROAS threshold if available, otherwise config default
        $adaptiveThresholds = AdaptiveThresholds::forCustomer($customer);
        $minRoas        = $adaptiveThresholds['min_roas_threshold'] ?? $reallocationRules['min_roas_threshold'] ?? config('optimization.budget_intelligence.reallocation_roas_ratio', 2.0);
        $maxShift       = $reallocationRules['max_shift_percentage'] ?? 20;
        $minDays        = $reallocationRules['min_data_days'] ?? 7;
        $minConversions = $reallocationRules['min_conversions'] ?? config('optimization.budget_intelligence.reallocation_min_conversions', 15);

        $recommendations = [];
        $performers = ['winners' => [], 'losers' => []];

        foreach ($campaigns as $campaign) {
            $metrics = null;
            $platform = null;

            // Try Google Ads first
            if ($campaign->google_ads_campaign_id && $customer->google_ads_customer_id) {
                $platform = 'google_ads';
                $customerId = $customer->google_ads_customer_id;
                $resourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

                try {
                    $getPerformance = new GetCampaignPerformance($customer, true);
                    $metrics = ($getPerformance)($customerId, $resourceName, 'LAST_30_DAYS');
                } catch (\Exception $e) {
                    Log::warning("BudgetIntelligenceAgent: Could not analyze Google campaign", [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Try Facebook Ads if no Google metrics
            if (!$metrics && $campaign->facebook_ads_campaign_id && $customer->facebook_ads_account_id) {
                $platform = 'facebook_ads';

                try {
                    $insightService = new FacebookInsightService($customer);
                    $dateEnd = now()->format('Y-m-d');
                    $dateStart = now()->subDays(30)->format('Y-m-d');

                    $insights = $insightService->getCampaignInsights(
                        $campaign->facebook_ads_campaign_id,
                        $dateStart,
                        $dateEnd
                    );

                    if (!empty($insights)) {
                        // Aggregate Facebook insights into a metrics structure
                        $totalSpend = 0;
                        $totalConversions = 0;
                        $totalConversionValue = 0;
                        foreach ($insights as $day) {
                            $totalSpend += (float) ($day['spend'] ?? 0);
                            $totalConversions += $insightService->parseAction($day['actions'] ?? null, 'purchase');
                            // Extract purchase ROAS value if available
                            foreach ($day['action_values'] ?? [] as $av) {
                                if (($av['action_type'] ?? '') === 'purchase') {
                                    $totalConversionValue += (float) ($av['value'] ?? 0);
                                }
                            }
                        }
                        $metrics = [
                            'cost_micros' => $totalSpend * 1000000,
                            'conversions' => $totalConversions,
                            'conversion_value' => $totalConversionValue,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::warning("BudgetIntelligenceAgent: Could not analyze Facebook campaign", [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$metrics) {
                continue;
            }

            $cost = ($metrics['cost_micros'] ?? 0) / 1000000;
            $conversions = $metrics['conversions'] ?? 0;
            $conversionValue = $metrics['conversion_value'] ?? 0;

            // Use actual conversion value from API if available, then customer AOV.
            // Without AOV the ROAS calculation is meaningless — skip reallocation for this campaign.
            $aov = $customer->average_order_value;
            if (!$aov && $conversionValue <= 0) {
                Log::info('BudgetIntelligenceAgent: Skipping campaign for reallocation — average_order_value not set and no conversion value from API', [
                    'campaign_id' => $campaign->id,
                    'customer_id' => $customer->id,
                ]);
                continue;
            }
            $revenue = $conversionValue > 0 ? $conversionValue : ($conversions * ($aov ?? 0));
            $roas = $cost > 0 ? $revenue / $cost : 0;

            if ($conversions >= $minConversions) {
                $entry = [
                    'campaign' => $campaign,
                    'platform' => $platform,
                    'roas' => $roas,
                    'cost' => $cost,
                    'conversions' => $conversions,
                ];

                if ($roas >= $minRoas * 1.5) {
                    $performers['winners'][] = $entry;
                } elseif ($roas < $minRoas) {
                    $performers['losers'][] = $entry;
                }
            }
        }

        // Generate recommendations and auto-execute small shifts
        $autoExecuteThreshold = 10; // Auto-execute shifts ≤10% of budget
        if (!empty($performers['winners']) && !empty($performers['losers'])) {
            foreach ($performers['losers'] as $loser) {
                $shiftPercent = min($maxShift, 10); // Start conservative
                $shiftAmount = $loser['campaign']->daily_budget * ($shiftPercent / 100);
                $winner = $performers['winners'][0];

                $recommendation = [
                    'type' => 'budget_reallocation',
                    'from_campaign' => $loser['campaign']->name,
                    'from_campaign_id' => $loser['campaign']->id,
                    'from_platform' => $loser['platform'],
                    'from_roas' => round($loser['roas'], 2),
                    'to_campaign' => $winner['campaign']->name,
                    'to_campaign_id' => $winner['campaign']->id,
                    'to_platform' => $winner['platform'],
                    'to_roas' => round($winner['roas'], 2),
                    'shift_amount' => $shiftAmount,
                    'shift_percent' => $shiftPercent,
                    'auto_executed' => false,
                    'reason' => "Shift budget from underperforming campaign (ROAS: " . round($loser['roas'], 2) . ") to top performer",
                ];

                // Auto-execute small reallocation (≤10%) when confidence is high
                // Winner ROAS must be ≥2x loser ROAS and shift ≤ auto-execute threshold
                $confidenceHigh = $winner['roas'] >= ($loser['roas'] * 2) && $winner['conversions'] >= 10;
                if ($shiftPercent <= $autoExecuteThreshold && $confidenceHigh) {
                    $executed = $this->executeReallocation(
                        $loser['campaign'], $winner['campaign'],
                        $shiftAmount, $customer
                    );
                    $recommendation['auto_executed'] = $executed;

                    if ($executed) {
                        Log::info('BudgetIntelligenceAgent: Auto-executed budget reallocation', [
                            'from' => $loser['campaign']->name,
                            'to' => $winner['campaign']->name,
                            'amount' => $shiftAmount,
                        ]);
                    }
                }

                $recommendations[] = $recommendation;
            }
        }

        return $recommendations;
    }

    /**
     * Execute a budget reallocation: reduce loser's budget by $amount, increase winner's by $amount.
     */
    protected function executeReallocation(
        Campaign $fromCampaign,
        Campaign $toCampaign,
        float $shiftAmount,
        Customer $customer
    ): bool {
        try {
            $fromBudget = $fromCampaign->daily_budget ?? 0;
            $toBudget   = $toCampaign->daily_budget ?? 0;

            $newFromBudget = max(5.0, $fromBudget - $shiftAmount);
            $newToBudget   = $toBudget + $shiftAmount;

            // Wrap DB writes in a transaction so both campaigns update atomically.
            // API calls happen after so a DB failure rolls back local state.
            return DB::transaction(function () use ($fromCampaign, $toCampaign, $customer, $newFromBudget, $newToBudget) {
                $fromCampaign->update(['daily_budget' => $newFromBudget]);
                $toCampaign->update(['daily_budget' => $newToBudget]);

                $fromSuccess = $this->updateCampaignBudget($fromCampaign, $customer, $newFromBudget);
                $toSuccess   = $this->updateCampaignBudget($toCampaign, $customer, $newToBudget);

                if (!$fromSuccess || !$toSuccess) {
                    throw new \RuntimeException('API budget update failed during reallocation');
                }

                return true;
            });
        } catch (\Exception $e) {
            Log::error('BudgetIntelligenceAgent: Failed to execute reallocation', [
                'from'  => $fromCampaign->id,
                'to'    => $toCampaign->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Compare Google vs Facebook ROAS over the last 3 days.
     * If one platform is >2x the other with sufficient volume, push a reallocation
     * recommendation into the CampaignOptimizationAgent cache for dashboard display.
     *
     * Does NOT auto-apply — cross-platform budget moves are too risky to automate.
     */
    public function checkCrossPlatformReallocation(Customer $customer): ?array
    {
        $cacheKey = "cross_platform_reallocation:{$customer->id}";

        $campaignIds = $customer->campaigns()
            ->where('status', 'active')
            ->pluck('id');

        if ($campaignIds->isEmpty()) {
            return null;
        }

        $since = now()->subDays(3)->toDateString();

        $google = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->where('date', '>=', $since)
            ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value')
            ->first();

        $facebook = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->where('date', '>=', $since)
            ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value')
            ->first();

        $gCost  = (float) ($google->cost  ?? 0);
        $fCost  = (float) ($facebook->cost ?? 0);
        $totalSpend = $gCost + $fCost;

        if ($totalSpend < 100) {
            return null;
        }

        $gConv = (float) ($google->conversions  ?? 0);
        $fConv = (float) ($facebook->conversions ?? 0);

        if ($gConv < 15 || $fConv < 15) {
            return null;
        }

        $aov    = (float) ($customer->average_order_value ?? 0);
        $gValue = (float) ($google->conversion_value   ?? 0) ?: ($gConv * $aov);
        $fValue = (float) ($facebook->conversion_value ?? 0) ?: ($fConv * $aov);

        if ($gValue <= 0 || $fValue <= 0) {
            return null;
        }

        $gRoas = $gCost > 0 ? $gValue / $gCost : 0;
        $fRoas = $fCost > 0 ? $fValue / $fCost : 0;

        if ($gRoas <= 0 || $fRoas <= 0) {
            return null;
        }

        $ratio         = $gRoas / $fRoas;
        $strongPlatform = null;
        $weakPlatform   = null;
        $strongRoas     = 0;
        $weakRoas       = 0;
        $weakDailySpend = 0;

        if ($ratio >= 2.0) {
            $strongPlatform  = 'google_ads';
            $weakPlatform    = 'facebook_ads';
            $strongRoas      = $gRoas;
            $weakRoas        = $fRoas;
            $weakDailySpend  = $fCost / 3;
        } elseif ((1 / $ratio) >= 2.0) {
            $strongPlatform  = 'facebook_ads';
            $weakPlatform    = 'google_ads';
            $strongRoas      = $fRoas;
            $weakRoas        = $gRoas;
            $weakDailySpend  = $gCost / 3;
        }

        if (!$strongPlatform) {
            Cache::forget($cacheKey);
            return null;
        }

        $shiftAmount = round($weakDailySpend * 0.10, 2);
        $recommendation = [
            'type'            => 'cross_platform_reallocation',
            'strong_platform' => $strongPlatform,
            'weak_platform'   => $weakPlatform,
            'strong_roas'     => round($strongRoas, 2),
            'weak_roas'       => round($weakRoas, 2),
            'ratio'           => round(max($ratio, 1 / $ratio), 2),
            'suggested_shift' => $shiftAmount,
            'shift_direction' => "Move \${$shiftAmount}/day from {$weakPlatform} → {$strongPlatform}",
            'reason'          => ucfirst(str_replace('_', ' ', $strongPlatform)) . " ROAS is " . round(max($ratio, 1 / $ratio), 1) . "x higher than " . ucfirst(str_replace('_', ' ', $weakPlatform)) . " over the last 3 days",
            'google_roas'     => round($gRoas, 2),
            'facebook_roas'   => round($fRoas, 2),
            'google_spend_3d' => round($gCost, 2),
            'facebook_spend_3d' => round($fCost, 2),
            'recorded_at'     => now()->toIso8601String(),
            'auto_applied'    => false,
        ];

        Cache::put($cacheKey, $recommendation, now()->addHours(24));

        Log::info("BudgetIntelligenceAgent: Cross-platform reallocation opportunity detected", [
            'customer_id'     => $customer->id,
            'strong_platform' => $strongPlatform,
            'strong_roas'     => round($strongRoas, 2),
            'weak_roas'       => round($weakRoas, 2),
            'suggested_shift' => $shiftAmount,
        ]);

        return $recommendation;
    }

    /**
     * Update a campaign's budget on its platform.
     */
    protected function updateCampaignBudget(Campaign $campaign, Customer $customer, float $newDailyBudget): bool
    {
        if ($campaign->google_ads_campaign_id && $customer->google_ads_customer_id) {
            $customerId = $customer->google_ads_customer_id;
            $resourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
            $updateBudget = new UpdateCampaignBudget($customer, true);
            return (bool) ($updateBudget)($customerId, $resourceName, $newDailyBudget * 1000000);
        }

        if ($campaign->facebook_ads_campaign_id && $customer->facebook_ads_account_id) {
            $adSetService = new FacebookAdSetService($customer);
            $adSets = $adSetService->listAdSets($campaign->facebook_ads_campaign_id);
            if (!empty($adSets)) {
                // Apply proportionally across ad sets
                $totalAdSetBudget = collect($adSets)->sum(fn($as) => ($as['daily_budget'] ?? 0) / 100);
                foreach ($adSets as $adSet) {
                    $currentBudget = ($adSet['daily_budget'] ?? 0) / 100;
                    if ($currentBudget <= 0 || $totalAdSetBudget <= 0) continue;
                    $proportion = $currentBudget / $totalAdSetBudget;
                    $newAdSetBudget = max(500, (int) round($newDailyBudget * $proportion * 100)); // min $5 in cents
                    $adSetService->updateAdSet($adSet['id'], ['daily_budget' => $newAdSetBudget]);
                }
                return true;
            }
        }

        return false;
    }
}
