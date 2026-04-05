<?php

namespace App\Services\Reporting;

use App\Models\Customer;
use App\Models\Campaign;
use App\Models\KeywordQualityScore;
use App\Models\CampaignHourlyPerformance;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\Reporting\QualityScoreTrendingService;
use Illuminate\Support\Facades\Log;

class ExecutiveReportService
{
    protected GeminiService $gemini;
    protected QualityScoreTrendingService $trendingService;

    public function __construct(GeminiService $gemini, QualityScoreTrendingService $trendingService)
    {
        $this->gemini = $gemini;
        $this->trendingService = $trendingService;
    }

    /**
     * Generate an executive performance report for a customer.
     *
     * @param Customer $customer
     * @param string $period 'weekly' or 'monthly'
     * @return array
     */
    public function generate(Customer $customer, string $period = 'weekly'): array
    {
        $days = $period === 'monthly' ? 30 : 7;
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();

        $campaigns = Campaign::where('customer_id', $customer->id)
            ->whereNotNull('google_ads_campaign_id')
            ->get();

        $googlePerformance = $this->getGooglePerformance($customer, $campaigns, $days);
        $keywordInsights = $this->getKeywordInsights($customer, $days);
        $hourlyInsights = $this->getHourlyInsights($customer, $days);
        $qsTrends = $this->trendingService->getTrends($customer, $days);

        $summary = $this->calculateSummary($googlePerformance);

        $report = [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'period' => [
                'type' => $period,
                'start' => $startDate,
                'end' => $endDate,
                'days' => $days,
            ],
            'summary' => $summary,
            'campaigns' => $googlePerformance,
            'keyword_insights' => $keywordInsights,
            'quality_score_trends' => $qsTrends,
            'hourly_insights' => $hourlyInsights,
            'generated_at' => now()->toIso8601String(),
        ];

        $report['ai_executive_summary'] = $this->generateNarrative($report);

        Log::info("Generated {$period} executive report for customer {$customer->id}", [
            'campaigns_analyzed' => count($campaigns),
        ]);

        return $report;
    }

    protected function getGooglePerformance(Customer $customer, $campaigns, int $days): array
    {
        $results = [];
        $dateRange = $days <= 7 ? 'LAST_7_DAYS' : 'LAST_30_DAYS';

        foreach ($campaigns as $campaign) {
            if (!$campaign->google_ads_campaign_id) {
                continue;
            }

            try {
                $service = new GetCampaignPerformance($customer, true);
                $customerId = $customer->google_ads_customer_id;
                $resourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

                $metrics = ($service)($customerId, $resourceName, $dateRange);
                if ($metrics) {
                    $metrics['campaign_name'] = $campaign->name;
                    $metrics['campaign_id'] = $campaign->id;
                    $results[] = $metrics;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to get performance for campaign {$campaign->id}: " . $e->getMessage());
            }
        }

        return $results;
    }

    protected function getKeywordInsights(Customer $customer, int $days): array
    {
        $recentScores = KeywordQualityScore::where('customer_id', $customer->id)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->get();

        if ($recentScores->isEmpty()) {
            return [
                'average_qs' => null,
                'total_keywords_tracked' => 0,
                'high_qs_keywords' => 0,
                'low_qs_keywords' => 0,
            ];
        }

        $withQs = $recentScores->whereNotNull('quality_score');

        return [
            'average_qs' => $withQs->avg('quality_score') ? round($withQs->avg('quality_score'), 1) : null,
            'total_keywords_tracked' => $recentScores->unique('keyword_text')->count(),
            'high_qs_keywords' => $withQs->where('quality_score', '>=', 7)->unique('keyword_text')->count(),
            'low_qs_keywords' => $withQs->where('quality_score', '<', 5)->unique('keyword_text')->count(),
            'top_keywords' => $withQs->sortByDesc('quality_score')
                ->unique('keyword_text')
                ->take(5)
                ->map(fn($k) => [
                    'keyword' => $k->keyword_text,
                    'quality_score' => $k->quality_score,
                    'impressions' => $k->impressions,
                ])->values()->toArray(),
            'worst_keywords' => $withQs->sortBy('quality_score')
                ->unique('keyword_text')
                ->take(5)
                ->map(fn($k) => [
                    'keyword' => $k->keyword_text,
                    'quality_score' => $k->quality_score,
                    'impressions' => $k->impressions,
                ])->values()->toArray(),
        ];
    }

    protected function getHourlyInsights(Customer $customer, int $days): array
    {
        $hourlyData = CampaignHourlyPerformance::where('customer_id', $customer->id)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->get();

        if ($hourlyData->isEmpty()) {
            return ['best_hours' => [], 'worst_hours' => []];
        }

        $byHour = $hourlyData->groupBy('hour')->map(function ($group) {
            return [
                'impressions' => $group->sum('impressions'),
                'clicks' => $group->sum('clicks'),
                'conversions' => $group->sum('conversions'),
                'spend' => $group->sum('spend'),
                'ctr' => $group->sum('impressions') > 0
                    ? round($group->sum('clicks') / $group->sum('impressions') * 100, 2)
                    : 0,
            ];
        })->sortByDesc('conversions');

        return [
            'best_hours' => $byHour->take(3)->toArray(),
            'worst_hours' => $byHour->sortBy('conversions')->take(3)->toArray(),
        ];
    }

    protected function calculateSummary(array $campaignPerformance): array
    {
        $totalImpressions = 0;
        $totalClicks = 0;
        $totalCostMicros = 0;
        $totalConversions = 0;

        foreach ($campaignPerformance as $campaign) {
            $totalImpressions += $campaign['impressions'] ?? 0;
            $totalClicks += $campaign['clicks'] ?? 0;
            $totalCostMicros += $campaign['cost_micros'] ?? 0;
            $totalConversions += $campaign['conversions'] ?? 0;
        }

        $totalCost = $totalCostMicros / 1_000_000;

        return [
            'total_campaigns' => count($campaignPerformance),
            'total_impressions' => $totalImpressions,
            'total_clicks' => $totalClicks,
            'total_cost' => round($totalCost, 2),
            'total_conversions' => $totalConversions,
            'blended_ctr' => $totalImpressions > 0 ? round($totalClicks / $totalImpressions * 100, 2) : 0,
            'blended_cpc' => $totalClicks > 0 ? round($totalCost / $totalClicks, 2) : 0,
            'blended_cpa' => $totalConversions > 0 ? round($totalCost / $totalConversions, 2) : 0,
        ];
    }

    protected function generateNarrative(array $report): ?string
    {
        $summary = $report['summary'];
        $kwInsights = $report['keyword_insights'];
        $period = $report['period']['type'];

        $prompt = "You are a senior SEM account strategist writing a {$period} executive summary for {$report['customer_name']}.\n\n"
            . "Performance Data:\n"
            . "- Campaigns: {$summary['total_campaigns']}\n"
            . "- Impressions: " . number_format($summary['total_impressions']) . "\n"
            . "- Clicks: " . number_format($summary['total_clicks']) . "\n"
            . "- CTR: {$summary['blended_ctr']}%\n"
            . "- Total Spend: \${$summary['total_cost']}\n"
            . "- Conversions: {$summary['total_conversions']}\n"
            . "- CPA: \${$summary['blended_cpa']}\n"
            . "- Avg CPC: \${$summary['blended_cpc']}\n";

        if ($kwInsights['average_qs']) {
            $prompt .= "\nKeyword Quality:\n"
                . "- Average Quality Score: {$kwInsights['average_qs']}/10\n"
                . "- Keywords tracked: {$kwInsights['total_keywords_tracked']}\n"
                . "- High QS keywords (7+): {$kwInsights['high_qs_keywords']}\n"
                . "- Low QS keywords (<5): {$kwInsights['low_qs_keywords']}\n";
        }

        $prompt .= "\nWrite a concise 3-4 paragraph executive summary covering: 1) Overall performance, 2) Key wins and concerns, 3) Recommended next steps. Be data-driven and specific.";

        try {
            $result = $this->gemini->generateContent(
                'gemini-3-flash-preview',
                $prompt,
                ['temperature' => 0.3, 'maxOutputTokens' => 1024],
                'You are a professional SEM analyst writing executive reports for business stakeholders.'
            );

            return $result['text'] ?? null;
        } catch (\Exception $e) {
            Log::warning("Failed to generate AI narrative: " . $e->getMessage());
            return null;
        }
    }
}
