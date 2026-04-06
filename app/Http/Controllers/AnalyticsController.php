<?php

namespace App\Http\Controllers;

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
        $customer = $request->user()->customer;
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
        $customer = $request->user()->customer;
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
        $customer = $request->user()->customer;

        // Demo touchpoints for visualization — in production these come from conversion tracking
        $sampleTouchpoints = [
            ['channel' => 'Google Ads', 'timestamp' => now()->subDays(7)->timestamp, 'campaign' => 'Brand Search'],
            ['channel' => 'Facebook Ads', 'timestamp' => now()->subDays(5)->timestamp, 'campaign' => 'Retargeting'],
            ['channel' => 'LinkedIn Ads', 'timestamp' => now()->subDays(3)->timestamp, 'campaign' => 'B2B Awareness'],
            ['channel' => 'Google Ads', 'timestamp' => now()->subDays(1)->timestamp, 'campaign' => 'Non-Brand Search'],
        ];

        $models = $this->attribution->attributeAll($sampleTouchpoints, 100.0);

        return Inertia::render('Analytics/Attribution', [
            'models' => $models,
            'touchpoints' => $sampleTouchpoints,
        ]);
    }
}
