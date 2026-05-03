<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\CampaignHourlyPerformance;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;

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

    // -------------------------------------------------------------------------
    // Per-campaign anomaly thresholds
    // -------------------------------------------------------------------------

    /**
     * Compute anomaly-detection and regression thresholds for a single campaign.
     *
     * Values are derived from that campaign's own 30-day daily performance
     * variance, so a naturally volatile campaign needs a larger deviation to
     * trigger than a stable one.  Falls back to config defaults when fewer than
     * min_history_days of data exist.
     *
     * Returned keys (all keyed by their config counterparts):
     *   ctr_drop_threshold, cpc_spike_threshold, cvr_drop_threshold
     *   min_impressions_anomaly, min_clicks_cpc, min_clicks_cvr
     *   cpa_regression_tolerance, roas_regression_tolerance
     *   budget_cut_cpc, budget_cut_cvr
     */
    public static function forCampaign(Campaign $campaign): array
    {
        $overrides = $campaign->customer?->agent_thresholds ?? [];
        $computed  = static::computeCampaignBaselines($campaign);
        $defaults  = static::anomalyConfigDefaults();

        return array_merge($defaults, $computed, $overrides);
    }

    /**
     * Config-sourced fallback defaults — used when history is insufficient.
     */
    protected static function anomalyConfigDefaults(): array
    {
        $cfg = config('optimization.anomaly_detection', []);

        return [
            'ctr_drop_threshold'      => $cfg['ctr_drop_default']        ?? 0.25,
            'cpc_spike_threshold'     => $cfg['cpc_spike_default']        ?? 0.50,
            'cvr_drop_threshold'      => $cfg['cvr_drop_default']         ?? 0.30,
            'min_impressions_anomaly' => $cfg['min_impressions']          ?? 100,
            'min_clicks_cpc'          => $cfg['min_clicks_cpc']           ?? 10,
            'min_clicks_cvr'          => $cfg['min_clicks_cvr']           ?? 20,
            'cpa_regression_tolerance'  => $cfg['regression_tolerance_min'] ?? 0.20,
            'roas_regression_tolerance' => $cfg['regression_tolerance_min'] ?? 0.20,
            'budget_cut_cpc'          => $cfg['budget_cut_cpc']           ?? 0.20,
            'budget_cut_cvr'          => $cfg['budget_cut_cvr']           ?? 0.25,
        ];
    }

    /**
     * Derive thresholds from historical daily variance for this campaign.
     *
     * Method: coefficient of variation (CV = stddev/mean) scaled by 2 then
     * clamped to config [min, max] bounds.  CV×2 means we alert when today's
     * metric is more than two typical day-to-day swings away from the norm,
     * which gives a ~95% false-positive rate on a normally-distributed signal.
     */
    protected static function computeCampaignBaselines(Campaign $campaign): array
    {
        $model = $campaign->google_ads_campaign_id
            ? GoogleAdsPerformanceData::class
            : ($campaign->facebook_ads_campaign_id ? FacebookAdsPerformanceData::class : null);

        if (!$model) {
            return [];
        }

        $cfg      = config('optimization.anomaly_detection', []);
        $minDays  = $cfg['min_history_days'] ?? 7;

        // Aggregate to one row per day so multi-row campaigns don't skew stddev
        $rows = $model::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(30))
            ->groupBy('date')
            ->selectRaw('SUM(impressions) as imp, SUM(clicks) as clk, SUM(cost) as cst, SUM(conversions) as conv')
            ->get();

        if ($rows->count() < $minDays) {
            return [];
        }

        $ctrs  = [];
        $cpcs  = [];
        $cvrs  = [];
        $cpas  = [];
        $imps  = [];
        $clks  = [];

        foreach ($rows as $row) {
            $imp  = (float) $row->imp;
            $clk  = (float) $row->clk;
            $cst  = (float) $row->cst;
            $conv = (float) $row->conv;

            $imps[] = $imp;
            $clks[]  = $clk;

            if ($imp > 0) $ctrs[] = $clk / $imp;
            if ($clk > 0) $cpcs[] = $cst / $clk;
            if ($clk > 0) $cvrs[] = $conv / $clk;
            if ($conv > 0) $cpas[] = $cst / $conv;
        }

        $computed = [];

        // --- anomaly detection thresholds (2 × coefficient of variation) ---

        if (count($ctrs) >= $minDays) {
            $cv = static::cv($ctrs);
            $computed['ctr_drop_threshold'] = static::clamp(
                2 * $cv,
                $cfg['ctr_drop_min'] ?? 0.15,
                $cfg['ctr_drop_max'] ?? 0.50
            );
        }

        if (count($cpcs) >= $minDays) {
            $cv = static::cv($cpcs);
            $computed['cpc_spike_threshold'] = static::clamp(
                2 * $cv,
                $cfg['cpc_spike_min'] ?? 0.30,
                $cfg['cpc_spike_max'] ?? 1.00
            );
        }

        if (count($cvrs) >= $minDays) {
            $cv = static::cv($cvrs);
            $computed['cvr_drop_threshold'] = static::clamp(
                2 * $cv,
                $cfg['cvr_drop_min'] ?? 0.20,
                $cfg['cvr_drop_max'] ?? 0.50
            );
        }

        // --- volume-based minimum sample sizes ---

        $avgDailyImpressions = count($imps) > 0 ? array_sum($imps) / count($imps) : 0;
        $avgDailyClicks      = count($clks) > 0 ? array_sum($clks) / count($clks)  : 0;

        if ($avgDailyImpressions > 0) {
            $computed['min_impressions_anomaly'] = (int) max(
                $cfg['min_impressions'] ?? 100,
                round($avgDailyImpressions * 0.30)
            );
        }

        if ($avgDailyClicks > 0) {
            $computed['min_clicks_cpc'] = (int) max(
                $cfg['min_clicks_cpc'] ?? 10,
                round($avgDailyClicks * 0.30)
            );
            $computed['min_clicks_cvr'] = (int) max(
                $cfg['min_clicks_cvr'] ?? 20,
                round($avgDailyClicks * 0.50)
            );
        }

        // --- regression tolerance (how much can CPA/ROAS drift before reverting) ---
        // Uses CPA variance as a proxy for both metrics; high CPA volatility means
        // a wider tolerance band before triggering strategy reversion.

        if (count($cpas) >= $minDays) {
            $cv = static::cv($cpas);
            $tolerance = static::clamp(
                $cv,
                $cfg['regression_tolerance_min'] ?? 0.15,
                $cfg['regression_tolerance_max'] ?? 0.40
            );
            $computed['cpa_regression_tolerance']  = $tolerance;
            $computed['roas_regression_tolerance'] = $tolerance;
        }

        return $computed;
    }

    /**
     * Sample coefficient of variation (stddev / mean).  Returns 0 if mean is 0.
     */
    private static function cv(array $values): float
    {
        $mean = count($values) > 0 ? array_sum($values) / count($values) : 0;
        if ($mean == 0) {
            return 0.0;
        }
        return static::stddev($values) / $mean;
    }

    /**
     * Sample standard deviation (n-1 denominator).
     */
    private static function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean     = array_sum($values) / $n;
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / ($n - 1);
        return sqrt($variance);
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return round(max($min, min($max, $value)), 4);
    }
}
