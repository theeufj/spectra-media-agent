<?php

namespace App\Http\Controllers;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Services\Attribution\AttributionService;
use App\Services\Reporting\CrossPlatformAnalyticsService;
use App\Services\Reporting\ExecutiveReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function __construct(
        protected CrossPlatformAnalyticsService $analytics,
        protected AttributionService $attribution,
    ) {}

    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $days = (int) $request->get('days', 30);

        $summary = $this->analytics->getSummary($customer, $days);
        $timeSeries = $this->analytics->getDailyTimeSeries($customer, $days);
        $funnel = $this->analytics->getFunnelAnalysis($customer, $days);

        return Inertia::render('Analytics/Index', [
            'summary' => $summary,
            'timeSeries' => $timeSeries,
            'funnel' => $funnel,
            'days' => $days,
        ]);
    }

    public function crossPlatform(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $days = (int) $request->get('days', 30);

        $comparison = $this->analytics->getPlatformComparison($customer, $days);
        $timeSeries = $this->analytics->getDailyTimeSeries($customer, $days);

        return Inertia::render('Analytics/CrossPlatform', [
            'comparison' => $comparison,
            'timeSeries' => $timeSeries,
            'days' => $days,
        ]);
    }

    public function attribution(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');

        $conversions = AttributionConversion::forCustomer($customer->id)
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get()
            ->toArray();

        // Aggregate by channel for each attribution model
        $models = ['last_click', 'first_click', 'linear', 'time_decay', 'position_based'];
        $channelBreakdown = [];
        foreach ($models as $model) {
            $channelBreakdown[$model] = $this->attribution->aggregateByChannel($conversions, $model);
        }

        // Recent touchpoints for journey visualization
        $recentTouchpoints = AttributionTouchpoint::forCustomer($customer->id)
            ->orderBy('touched_at', 'desc')
            ->limit(100)
            ->get()
            ->toArray();

        // Summary stats
        $totalConversions = count($conversions);
        $totalValue = array_sum(array_column($conversions, 'conversion_value'));
        $avgTouchpoints = $totalConversions > 0
            ? array_sum(array_map(fn($c) => count($c['touchpoints'] ?? []), $conversions)) / $totalConversions
            : 0;

        return Inertia::render('Analytics/Attribution', [
            'summary' => [
                'total_conversions' => $totalConversions,
                'total_value' => round($totalValue, 2),
                'avg_touchpoints' => round($avgTouchpoints, 1),
            ],
            'channelBreakdown' => $channelBreakdown,
            'recentTouchpoints' => $recentTouchpoints,
            'conversions' => array_slice($conversions, 0, 50),
        ]);
    }
}
