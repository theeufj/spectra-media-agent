<?php

namespace App\Services\Reporting;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use Illuminate\Support\Facades\Log;

/**
 * Cross-Platform Analytics Service.
 *
 * Aggregates performance data from Google, Facebook, Microsoft, and LinkedIn
 * into unified metrics for the Advanced Analytics Dashboard.
 */
class CrossPlatformAnalyticsService
{
    /**
     * Get unified summary across all platforms for a customer.
     */
    public function getSummary(Customer $customer, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();
        $campaignIds = Campaign::where('customer_id', $customer->id)->pluck('id');

        $google = $this->getPlatformMetrics('google', $campaignIds, $startDate);
        $facebook = $this->getPlatformMetrics('facebook', $campaignIds, $startDate);
        $microsoft = $this->getPlatformMetrics('microsoft', $campaignIds, $startDate);
        $linkedin = $this->getPlatformMetrics('linkedin', $campaignIds, $startDate);

        $platforms = compact('google', 'facebook', 'microsoft', 'linkedin');

        $totals = [
            'impressions' => 0,
            'clicks' => 0,
            'cost' => 0,
            'conversions' => 0,
            'conversion_value' => 0,
        ];

        foreach ($platforms as $p) {
            $totals['impressions'] += $p['impressions'];
            $totals['clicks'] += $p['clicks'];
            $totals['cost'] += $p['cost'];
            $totals['conversions'] += $p['conversions'];
            $totals['conversion_value'] += $p['conversion_value'];
        }

        $totals['ctr'] = $totals['impressions'] > 0 ? round($totals['clicks'] / $totals['impressions'] * 100, 2) : 0;
        $totals['cpc'] = $totals['clicks'] > 0 ? round($totals['cost'] / $totals['clicks'], 2) : 0;
        $totals['cpa'] = $totals['conversions'] > 0 ? round($totals['cost'] / $totals['conversions'], 2) : 0;
        $totals['roas'] = $totals['cost'] > 0 ? round($totals['conversion_value'] / $totals['cost'], 2) : 0;

        return [
            'totals' => $totals,
            'platforms' => $platforms,
            'days' => $days,
            'period' => [
                'start' => $startDate,
                'end' => now()->toDateString(),
            ],
        ];
    }

    /**
     * Get daily time-series data across all platforms.
     */
    public function getDailyTimeSeries(Customer $customer, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();
        $campaignIds = Campaign::where('customer_id', $customer->id)->pluck('id');

        $series = [];

        // Google
        $google = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('date, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Facebook
        $facebook = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('date, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Microsoft
        $microsoft = MicrosoftAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('date, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // LinkedIn
        $linkedin = LinkedInAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('date, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Merge into unified time series
        $allDates = collect()
            ->merge($google->keys())
            ->merge($facebook->keys())
            ->merge($microsoft->keys())
            ->merge($linkedin->keys())
            ->unique()
            ->sort()
            ->values();

        foreach ($allDates as $date) {
            $g = $google->get($date);
            $f = $facebook->get($date);
            $m = $microsoft->get($date);
            $l = $linkedin->get($date);

            $series[] = [
                'date' => $date,
                'google' => $this->extractMetrics($g),
                'facebook' => $this->extractMetrics($f),
                'microsoft' => $this->extractMetrics($m),
                'linkedin' => $this->extractMetrics($l),
                'total' => [
                    'impressions' => ($g->impressions ?? 0) + ($f->impressions ?? 0) + ($m->impressions ?? 0) + ($l->impressions ?? 0),
                    'clicks' => ($g->clicks ?? 0) + ($f->clicks ?? 0) + ($m->clicks ?? 0) + ($l->clicks ?? 0),
                    'cost' => round(($g->cost ?? 0) + ($f->cost ?? 0) + ($m->cost ?? 0) + ($l->cost ?? 0), 2),
                    'conversions' => ($g->conversions ?? 0) + ($f->conversions ?? 0) + ($m->conversions ?? 0) + ($l->conversions ?? 0),
                ],
            ];
        }

        return $series;
    }

    /**
     * Get platform comparison metrics for a pie/bar chart.
     */
    public function getPlatformComparison(Customer $customer, int $days = 30): array
    {
        $summary = $this->getSummary($customer, $days);
        $platforms = $summary['platforms'];
        $totals = $summary['totals'];

        $comparison = [];
        foreach ($platforms as $name => $metrics) {
            $comparison[] = [
                'platform' => ucfirst($name),
                'impressions' => $metrics['impressions'],
                'clicks' => $metrics['clicks'],
                'cost' => $metrics['cost'],
                'conversions' => $metrics['conversions'],
                'roas' => $metrics['roas'],
                'spend_share' => $totals['cost'] > 0 ? round($metrics['cost'] / $totals['cost'] * 100, 1) : 0,
                'conversion_share' => $totals['conversions'] > 0 ? round($metrics['conversions'] / $totals['conversions'] * 100, 1) : 0,
            ];
        }

        return $comparison;
    }

    /**
     * Get funnel analysis: impressions → clicks → conversions with drop-off rates.
     */
    public function getFunnelAnalysis(Customer $customer, int $days = 30): array
    {
        $summary = $this->getSummary($customer, $days);
        $t = $summary['totals'];

        return [
            'stages' => [
                ['name' => 'Impressions', 'value' => $t['impressions'], 'rate' => 100],
                ['name' => 'Clicks', 'value' => $t['clicks'], 'rate' => $t['impressions'] > 0 ? round($t['clicks'] / $t['impressions'] * 100, 2) : 0],
                ['name' => 'Conversions', 'value' => $t['conversions'], 'rate' => $t['clicks'] > 0 ? round($t['conversions'] / $t['clicks'] * 100, 2) : 0],
            ],
            'overall_conversion_rate' => $t['impressions'] > 0 ? round($t['conversions'] / $t['impressions'] * 100, 4) : 0,
            'cost_per_funnel_stage' => [
                'cpm' => $t['impressions'] > 0 ? round($t['cost'] / $t['impressions'] * 1000, 2) : 0,
                'cpc' => $t['cpc'],
                'cpa' => $t['cpa'],
            ],
        ];
    }

    protected function getPlatformMetrics(string $platform, $campaignIds, string $startDate): array
    {
        $model = match ($platform) {
            'google' => GoogleAdsPerformanceData::class,
            'facebook' => FacebookAdsPerformanceData::class,
            'microsoft' => MicrosoftAdsPerformanceData::class,
            'linkedin' => LinkedInAdsPerformanceData::class,
            default => null,
        };

        if (!$model) {
            return $this->emptyMetrics();
        }

        $data = $model::whereIn('campaign_id', $campaignIds)
            ->where('date', '>=', $startDate)
            ->get();

        $impressions = $data->sum('impressions');
        $clicks = $data->sum('clicks');
        $cost = round($data->sum('cost'), 2);
        $conversions = $data->sum('conversions');
        $conversionValue = round($data->sum('conversion_value'), 2);

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'cost' => $cost,
            'conversions' => $conversions,
            'conversion_value' => $conversionValue,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0,
            'cpc' => $clicks > 0 ? round($cost / $clicks, 2) : 0,
            'cpa' => $conversions > 0 ? round($cost / $conversions, 2) : 0,
            'roas' => $cost > 0 ? round($conversionValue / $cost, 2) : 0,
        ];
    }

    protected function emptyMetrics(): array
    {
        return [
            'impressions' => 0, 'clicks' => 0, 'cost' => 0,
            'conversions' => 0, 'conversion_value' => 0,
            'ctr' => 0, 'cpc' => 0, 'cpa' => 0, 'roas' => 0,
        ];
    }

    protected function extractMetrics($row): array
    {
        if (!$row) return $this->emptyMetrics();

        return [
            'impressions' => $row->impressions ?? 0,
            'clicks' => $row->clicks ?? 0,
            'cost' => round($row->cost ?? 0, 2),
            'conversions' => $row->conversions ?? 0,
            'conversion_value' => round($row->conversion_value ?? 0, 2),
        ];
    }
}
