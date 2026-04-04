<?php

namespace App\Services\Agents;

use App\Models\CampaignHourlyPerformance;
use App\Models\Customer;

/**
 * AdaptiveThresholds
 *
 * Computes per-account baseline thresholds from historical performance data.
 * Falls back to static defaults when insufficient data exists.
 *
 * Used by: BudgetIntelligenceAgent, CreativeIntelligenceAgent, SelfHealingAgent
 */
class AdaptiveThresholds
{
    /**
     * Static defaults — used when no historical data is available.
     */
    protected static array $defaults = [
        'min_ctr' => 0.005,               // 0.5% CTR
        'min_impressions_for_decision' => 1000,
        'max_spend_no_conversion' => 50.00,
        'min_roas_threshold' => 1.5,
        'auto_pause_min_impressions' => 2000,
        'auto_pause_max_ctr' => 0.005,
    ];

    /**
     * Get adaptive thresholds for a customer.
     * Uses stored overrides > computed baselines > static defaults.
     */
    public static function forCustomer(Customer $customer): array
    {
        // 1. Check for manual overrides stored on the customer
        $overrides = $customer->agent_thresholds ?? [];

        // 2. Compute baselines from historical data
        $computed = static::computeBaselines($customer->id);

        // 3. Merge: overrides > computed > defaults
        return array_merge(static::$defaults, $computed, $overrides);
    }

    /**
     * Compute per-account baselines from the last 60 days of performance data.
     * A niche B2B account's "good CTR" is different from e-commerce.
     */
    protected static function computeBaselines(int $customerId): array
    {
        $stats = CampaignHourlyPerformance::where('customer_id', $customerId)
            ->where('date', '>=', now()->subDays(60))
            ->where('impressions', '>', 0)
            ->selectRaw('
                AVG(ctr) as avg_ctr,
                STDDEV(ctr) as stddev_ctr,
                AVG(roas) as avg_roas,
                PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY ctr) as p25_ctr,
                AVG(spend) as avg_hourly_spend,
                SUM(impressions) as total_impressions,
                COUNT(*) as data_points
            ')
            ->first();

        if (!$stats || $stats->data_points < 100) {
            return []; // Not enough data — use defaults
        }

        $computed = [];

        // Min CTR = 25th percentile of this account's CTR (what's "bad" for THEM)
        if ($stats->p25_ctr > 0) {
            $computed['min_ctr'] = round($stats->p25_ctr, 6);
            $computed['auto_pause_max_ctr'] = round($stats->p25_ctr * 0.5, 6); // Half of their 25th percentile
        }

        // Min ROAS = 80% of their average (below this is underperforming for them)
        if ($stats->avg_roas > 0) {
            $computed['min_roas_threshold'] = round($stats->avg_roas * 0.8, 2);
        }

        // Max spend before no-conversion pause = 2x avg hourly spend * 24 / expected conversions
        // Simplified: scale the $50 default proportionally to their avg daily spend
        if ($stats->avg_hourly_spend > 0) {
            $avgDailySpend = $stats->avg_hourly_spend * 24;
            // Cap at 10% of daily spend or $50, whichever is higher
            $computed['max_spend_no_conversion'] = round(max(50, $avgDailySpend * 0.1), 2);
        }

        // Scale min impressions based on their traffic volume
        if ($stats->total_impressions > 0) {
            $avgDailyImpressions = $stats->total_impressions / max(1, $stats->data_points) * 24;
            // Decision threshold = ~10% of their daily impressions, minimum 500
            $computed['min_impressions_for_decision'] = (int) max(500, min(5000, $avgDailyImpressions * 0.1));
            $computed['auto_pause_min_impressions'] = $computed['min_impressions_for_decision'] * 2;
        }

        return $computed;
    }

    /**
     * Get a single threshold value for a customer.
     */
    public static function get(Customer $customer, string $key, $default = null)
    {
        $thresholds = static::forCustomer($customer);
        return $thresholds[$key] ?? $default ?? static::$defaults[$key] ?? null;
    }
}
