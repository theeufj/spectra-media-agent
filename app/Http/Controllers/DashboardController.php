<?php

namespace App\Http\Controllers;

use App\Models\AgentActivity;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Services\CreativeQuotaService;
use App\Services\Reporting\CrossPlatformAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // If user has no customers yet, redirect to quick start onboarding
        if (!session('active_customer_id') || !$user->customers()->where('customers.id', session('active_customer_id'))->exists()) {
            $firstCustomer = $user->customers()->first();
            if ($firstCustomer) {
                session(['active_customer_id' => $firstCustomer->id]);
            } else {
                return redirect()->route('quick-start');
            }
        }

        $activeCustomer = $user->customers()->findOrFail(session('active_customer_id'));

        $campaigns = $activeCustomer->campaigns()
            ->with(['strategies'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Build ROI / analytics data for all campaigns
        $days = (int) $request->get('days', 30);
        $since = now()->subDays($days);
        $campaignIds = $campaigns->pluck('id');

        $platformData = $this->aggregatePlatformData($campaignIds, $since);
        $campaignBreakdown = $this->buildCampaignBreakdown($campaigns, $since);
        $dailyTrend = $this->buildDailyTrend($campaignIds, $since);
        $projections = $this->buildProjections($platformData, $activeCustomer, $days);

        // Cross-platform comparison
        $analytics = app(CrossPlatformAnalyticsService::class);
        $crossPlatformComparison = $analytics->getPlatformComparison($activeCustomer, $days);
        $funnel = $analytics->getFunnelAnalysis($activeCustomer, $days);

        return Inertia::render('Dashboard/Index', [
            'campaigns' => $campaigns,
            'defaultCampaign' => $campaigns->first(),
            'days' => $days,
            'usageStats' => [
                'free_generations_used' => $user->free_generations_used,
                'cro_audits_used' => $activeCustomer->cro_audits_used,
                'subscription_status' => $user->subscribed('default') ? 'active' : 'inactive',
            ],
            'creativeUsage' => app(CreativeQuotaService::class)->getUsageSummary($user),
            'pendingTasks' => $this->getPendingTasks($campaigns),
            'healthAlerts' => $this->getHealthAlerts($campaigns),
            'agentActivities' => AgentActivity::where('customer_id', $activeCustomer->id)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get(),
            // Analytics data
            'platformData' => $platformData,
            'campaignBreakdown' => $campaignBreakdown,
            'dailyTrend' => $dailyTrend,
            'projections' => $projections,
            'crossPlatformComparison' => $crossPlatformComparison,
            'funnel' => $funnel,
            'trackingStatus' => [
                'provisioned' => (bool) $activeCustomer->gtm_container_id,
                'installed'   => (bool) $activeCustomer->gtm_installed,
                'setup_url'   => route('customers.gtm.setup', $activeCustomer->id),
            ],
        ]);
    }

    /**
     * Per-campaign ROI data (called client-side when campaign changes).
     */
    public function campaignRoi(Request $request, \App\Models\Campaign $campaign)
    {
        $user = $request->user();
        $isOwner = $user->customers()->where('customers.id', $campaign->customer_id)->exists();
        if (!$isOwner && !$user->hasRole('admin')) {
            abort(403);
        }

        $days = (int) $request->get('days', 30);
        $since = now()->subDays($days);
        $cIds = collect([$campaign->id]);

        $platformData = $this->aggregatePlatformData($cIds, $since);
        $dailyTrend   = $this->buildDailyTrend($cIds, $since);

        $totalCost = collect($platformData)->sum('cost');
        $totalRevenue = collect($platformData)->sum('revenue');
        $totalConversions = collect($platformData)->sum('conversions');

        return response()->json([
            'platformData' => $platformData,
            'dailyTrend'   => $dailyTrend,
            'summary' => [
                'cost'        => round($totalCost, 2),
                'revenue'     => round($totalRevenue, 2),
                'conversions' => $totalConversions,
                'roas'        => $totalCost > 0 ? round($totalRevenue / $totalCost, 2) : 0,
                'cpa'         => $totalConversions > 0 ? round($totalCost / $totalConversions, 2) : 0,
            ],
        ]);
    }

    /**
     * Compute pending tasks that need user attention.
     */
    private function getPendingTasks($campaigns): array
    {
        $tasks = [];

        foreach ($campaigns as $campaign) {
            // Strategies awaiting sign-off
            $unsignedStrategies = $campaign->strategies
                ->whereNull('signed_off_at')
                ->where('status', 'pending_approval');

            foreach ($unsignedStrategies as $strategy) {
                $tasks[] = [
                    'id' => "sign-off-{$strategy->id}",
                    'type' => 'sign-off',
                    'title' => "Sign off {$strategy->campaign_type} strategy",
                    'description' => "Review and approve the {$strategy->platform} {$strategy->campaign_type} strategy",
                    'campaign_name' => $campaign->name,
                    'priority' => 'high',
                    'href' => "/campaigns/{$campaign->id}/strategies",
                ];
            }

            // Strategies with collateral ready for review (deployed but not signed off)
            $readyStrategies = $campaign->strategies
                ->whereNotNull('execution_result')
                ->whereNull('signed_off_at');

            foreach ($readyStrategies as $strategy) {
                if ($strategy->status !== 'pending_approval') {
                    $tasks[] = [
                        'id' => "review-collateral-{$strategy->id}",
                        'type' => 'review-collateral',
                        'title' => "Review {$strategy->campaign_type} collateral",
                        'description' => "Ad copy and creative assets are ready for review",
                        'campaign_name' => $campaign->name,
                        'priority' => 'medium',
                        'href' => "/campaigns/{$campaign->id}/{$strategy->id}/collateral",
                    ];
                }
            }

            // Campaigns ready to deploy (all strategies signed off, not yet deployed)
            $allSignedOff = $campaign->strategies->isNotEmpty()
                && $campaign->strategies->every(fn ($s) => $s->signed_off_at !== null);
            $noneDeployed = $campaign->strategies->every(fn ($s) => $s->deployment_status !== 'deployed');

            if ($allSignedOff && $noneDeployed && !$campaign->google_ads_campaign_id) {
                $tasks[] = [
                    'id' => "deploy-{$campaign->id}",
                    'type' => 'deploy',
                    'title' => "Deploy campaign",
                    'description' => "All strategies approved — ready to go live",
                    'campaign_name' => $campaign->name,
                    'priority' => 'high',
                    'href' => "/campaigns/{$campaign->id}/strategies",
                ];
            }
        }

        return $tasks;
    }

    /**
     * Compute health alerts for active campaigns.
     */
    private function getHealthAlerts($campaigns): array
    {
        $alerts = [];

        foreach ($campaigns as $campaign) {
            // Campaign with policy violations or disapproved status
            if ($campaign->primary_status === 'REMOVED' || $campaign->primary_status === 'PAUSED') {
                $reasons = is_array($campaign->primary_status_reasons)
                    ? implode(', ', $campaign->primary_status_reasons)
                    : '';
                $alerts[] = [
                    'id' => "status-{$campaign->id}",
                    'severity' => 'critical',
                    'title' => "Campaign {$campaign->primary_status}",
                    'message' => '"' . $campaign->name . '" is ' . $campaign->primary_status . '. ' . $reasons,
                    'campaign_name' => $campaign->name,
                ];
            }

            // Strategy deployment failures
            foreach ($campaign->strategies as $strategy) {
                if ($strategy->deployment_status === 'failed') {
                    $alerts[] = [
                        'id' => "deploy-fail-{$strategy->id}",
                        'severity' => 'critical',
                        'title' => 'Deployment failed',
                        'message' => '"' . $campaign->name . '" ' . $strategy->campaign_type . ' deployment failed: ' . ($strategy->deployment_error ?: 'Unknown error'),
                        'campaign_name' => $campaign->name,
                    ];
                }
            }

            // Budget exhaustion warning (end date approaching)
            if ($campaign->end_date && $campaign->end_date->diffInDays(now()) <= 3 && $campaign->end_date->isFuture()) {
                $alerts[] = [
                    'id' => "budget-{$campaign->id}",
                    'severity' => 'warning',
                    'title' => 'Campaign ending soon',
                    'message' => '"' . $campaign->name . '" ends in ' . $campaign->end_date->diffInDays(now()) . ' days',
                    'campaign_name' => $campaign->name,
                ];
            }

            // Strategies generating for too long (stuck)
            if ($campaign->isGeneratingStrategies()
                && $campaign->strategy_generation_started_at
                && $campaign->strategy_generation_started_at->diffInMinutes(now()) > 15) {
                $alerts[] = [
                    'id' => "stuck-{$campaign->id}",
                    'severity' => 'warning',
                    'title' => 'Strategy generation may be stuck',
                    'message' => '"' . $campaign->name . '" has been generating strategies for over 15 minutes',
                    'campaign_name' => $campaign->name,
                ];
            }
        }

        return $alerts;
    }

    // ── Analytics data aggregation ──────────────────────────────────

    private function aggregatePlatformData($campaignIds, Carbon $since): array
    {
        $platforms = [];
        $models = [
            'google'   => GoogleAdsPerformanceData::class,
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
                    'clicks'      => (int) $data->clicks,
                    'cost'        => round((float) $data->cost, 2),
                    'conversions' => (int) $data->conversions,
                    'revenue'     => round((float) $data->revenue, 2),
                    'roas'        => round($data->revenue / $data->cost, 2),
                    'cpa'         => $data->conversions > 0 ? round($data->cost / $data->conversions, 2) : 0,
                ];
            }
        }

        return $platforms;
    }

    private function buildCampaignBreakdown($campaigns, Carbon $since): array
    {
        $breakdown = [];
        $models = [
            GoogleAdsPerformanceData::class,
            FacebookAdsPerformanceData::class,
            MicrosoftAdsPerformanceData::class,
            LinkedInAdsPerformanceData::class,
        ];

        foreach ($campaigns as $campaign) {
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
                    'id'   => $campaign->id,
                    'name' => $campaign->name,
                    'daily_budget' => $campaign->daily_budget,
                    'cost' => round($totalCost, 2),
                    'revenue' => round($totalRevenue, 2),
                    'conversions' => $totalConversions,
                    'roas' => round($totalRevenue / $totalCost, 2),
                    'cpa'  => $totalConversions > 0 ? round($totalCost / $totalConversions, 2) : 0,
                    'budget_utilization' => $campaign->daily_budget > 0
                        ? round(($totalCost / ($campaign->daily_budget * 30)) * 100, 1)
                        : 0,
                ];
            }
        }

        usort($breakdown, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return $breakdown;
    }

    private function buildDailyTrend($campaignIds, Carbon $since): array
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
                // Ensure date is a string to safely use as an array key
                $date = $row->date instanceof \Carbon\Carbon ? $row->date->format('Y-m-d') : (string) $row->date;
                
                if (!isset($dailyMap[$date])) {
                    $dailyMap[$date] = ['date' => $date, 'cost' => 0, 'revenue' => 0, 'conversions' => 0];
                }
                $dailyMap[$date]['cost'] += (float) $row->cost;
                $dailyMap[$date]['revenue'] += (float) $row->revenue;
                $dailyMap[$date]['conversions'] += (int) $row->conversions;
            }
        }

        ksort($dailyMap);

        return array_map(fn ($d) => [
            'date'        => $d['date'],
            'cost'        => round($d['cost'], 2),
            'revenue'     => round($d['revenue'], 2),
            'conversions' => $d['conversions'],
            'roas'        => $d['cost'] > 0 ? round($d['revenue'] / $d['cost'], 2) : 0,
        ], array_values($dailyMap));
    }

    private function buildProjections(array $platformData, $customer, int $days): array
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
            'daily_avg_spend'            => round($totalCostPerDay, 2),
            'daily_avg_revenue'          => round($totalRevenuePerDay, 2),
            'daily_budget_total'         => round($totalBudgetPerDay, 2),
            'monthly_projected_spend'    => round($totalCostPerDay * 30, 2),
            'monthly_projected_revenue'  => round($totalRevenuePerDay * 30, 2),
            'monthly_projected_profit'   => round(($totalRevenuePerDay - $totalCostPerDay) * 30, 2),
            'quarterly_projected_spend'  => round($totalCostPerDay * 90, 2),
            'quarterly_projected_revenue' => round($totalRevenuePerDay * 90, 2),
            'budget_utilization'         => $totalBudgetPerDay > 0
                ? round(($totalCostPerDay / $totalBudgetPerDay) * 100, 1)
                : 0,
        ];
    }
}
