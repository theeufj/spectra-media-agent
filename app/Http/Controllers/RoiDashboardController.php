<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class RoiDashboardController extends Controller
{
    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);

        if (!$customer) {
            return redirect()->route('dashboard');
        }

        $days = (int) $request->input('days', 30);
        $since = now()->subDays($days);

        $campaigns = $customer->campaigns()->where('platform_status', 'ENABLED')->get();
        $campaignIds = $campaigns->pluck('id');

        // Aggregate per-platform spend & revenue
        $platformData = $this->aggregatePlatformData($campaignIds, $since);

        // Per-campaign breakdown
        $campaignBreakdown = $this->buildCampaignBreakdown($campaigns, $since);

        // Daily spend/revenue trend for chart
        $dailyTrend = $this->buildDailyTrend($campaignIds, $since);

        // Spending projections
        $projections = $this->buildProjections($platformData, $customer, $days);

        return Inertia::render('Analytics/Roi', [
            'days' => $days,
            'platformData' => $platformData,
            'campaignBreakdown' => $campaignBreakdown,
            'dailyTrend' => $dailyTrend,
            'projections' => $projections,
        ]);
    }

    protected function aggregatePlatformData($campaignIds, Carbon $since): array
    {
        $platforms = [];

        $models = [
            'google' => GoogleAdsPerformanceData::class,
            'facebook' => FacebookAdsPerformanceData::class,
            'microsoft' => MicrosoftAdsPerformanceData::class,
            'linkedin' => LinkedInAdsPerformanceData::class,
        ];

        foreach ($models as $name => $modelClass) {
            $data = $modelClass::whereIn('campaign_id', $campaignIds)
                ->where('date', '>=', $since->toDateString())
                ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as revenue')
                ->first();

            if ($data && $data->cost > 0) {
                $platforms[$name] = [
                    'impressions' => (int) $data->impressions,
                    'clicks' => (int) $data->clicks,
                    'cost' => round((float) $data->cost, 2),
                    'conversions' => (int) $data->conversions,
                    'revenue' => round((float) $data->revenue, 2),
                    'roas' => round($data->revenue / $data->cost, 2),
                    'cpa' => $data->conversions > 0 ? round($data->cost / $data->conversions, 2) : 0,
                ];
            }
        }

        return $platforms;
    }

    protected function buildCampaignBreakdown($campaigns, Carbon $since): array
    {
        $breakdown = [];

        foreach ($campaigns as $campaign) {
            $models = [
                GoogleAdsPerformanceData::class,
                FacebookAdsPerformanceData::class,
                MicrosoftAdsPerformanceData::class,
                LinkedInAdsPerformanceData::class,
            ];

            $totalCost = 0;
            $totalRevenue = 0;
            $totalConversions = 0;

            foreach ($models as $modelClass) {
                $data = $modelClass::where('campaign_id', $campaign->id)
                    ->where('date', '>=', $since->toDateString())
                    ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as revenue')
                    ->first();

                if ($data) {
                    $totalCost += (float) $data->cost;
                    $totalRevenue += (float) $data->revenue;
                    $totalConversions += (int) $data->conversions;
                }
            }

            if ($totalCost > 0) {
                $breakdown[] = [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'daily_budget' => $campaign->daily_budget,
                    'cost' => round($totalCost, 2),
                    'revenue' => round($totalRevenue, 2),
                    'conversions' => $totalConversions,
                    'roas' => round($totalRevenue / $totalCost, 2),
                    'cpa' => $totalConversions > 0 ? round($totalCost / $totalConversions, 2) : 0,
                    'budget_utilization' => $campaign->daily_budget > 0
                        ? round(($totalCost / ($campaign->daily_budget * 30)) * 100, 1)
                        : 0,
                ];
            }
        }

        // Sort by cost descending
        usort($breakdown, fn($a, $b) => $b['cost'] <=> $a['cost']);

        return $breakdown;
    }

    protected function buildDailyTrend($campaignIds, Carbon $since): array
    {
        $models = [
            GoogleAdsPerformanceData::class,
            FacebookAdsPerformanceData::class,
            MicrosoftAdsPerformanceData::class,
            LinkedInAdsPerformanceData::class,
        ];

        $dailyMap = [];

        foreach ($models as $modelClass) {
            $rows = $modelClass::whereIn('campaign_id', $campaignIds)
                ->where('date', '>=', $since->toDateString())
                ->selectRaw('date, SUM(cost) as cost, SUM(conversion_value) as revenue, SUM(conversions) as conversions')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            foreach ($rows as $row) {
                $date = $row->date;
                if (!isset($dailyMap[$date])) {
                    $dailyMap[$date] = ['date' => $date, 'cost' => 0, 'revenue' => 0, 'conversions' => 0];
                }
                $dailyMap[$date]['cost'] += (float) $row->cost;
                $dailyMap[$date]['revenue'] += (float) $row->revenue;
                $dailyMap[$date]['conversions'] += (int) $row->conversions;
            }
        }

        ksort($dailyMap);

        return array_map(fn($d) => [
            'date' => $d['date'],
            'cost' => round($d['cost'], 2),
            'revenue' => round($d['revenue'], 2),
            'conversions' => $d['conversions'],
            'roas' => $d['cost'] > 0 ? round($d['revenue'] / $d['cost'], 2) : 0,
        ], array_values($dailyMap));
    }

    protected function buildProjections(array $platformData, Customer $customer, int $days): array
    {
        $totalCostPerDay = 0;
        $totalRevenuePerDay = 0;

        foreach ($platformData as $platform) {
            $totalCostPerDay += $platform['cost'] / max(1, $days);
            $totalRevenuePerDay += $platform['revenue'] / max(1, $days);
        }

        $totalBudgetPerDay = $customer->campaigns()
            ->where('platform_status', 'ENABLED')
            ->sum('daily_budget');

        return [
            'daily_avg_spend' => round($totalCostPerDay, 2),
            'daily_avg_revenue' => round($totalRevenuePerDay, 2),
            'daily_budget_total' => round($totalBudgetPerDay, 2),
            'monthly_projected_spend' => round($totalCostPerDay * 30, 2),
            'monthly_projected_revenue' => round($totalRevenuePerDay * 30, 2),
            'monthly_projected_profit' => round(($totalRevenuePerDay - $totalCostPerDay) * 30, 2),
            'quarterly_projected_spend' => round($totalCostPerDay * 90, 2),
            'quarterly_projected_revenue' => round($totalRevenuePerDay * 90, 2),
            'budget_utilization' => $totalBudgetPerDay > 0
                ? round(($totalCostPerDay / $totalBudgetPerDay) * 100, 1)
                : 0,
        ];
    }
}
