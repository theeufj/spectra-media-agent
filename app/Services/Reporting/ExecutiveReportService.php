<?php

namespace App\Services\Reporting;

use App\Models\AttributionConversion;
use App\Models\Customer;
use App\Models\Campaign;
use App\Models\AgentActivity;
use App\Models\KeywordQualityScore;
use App\Models\GoogleAdsPerformanceData;
use App\Models\CampaignHourlyPerformance;
use App\Models\FacebookAdsPerformanceData;
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
        $days = match ($period) {
            'monthly'   => 30,
            'quarterly' => 90,
            default     => 7,
        };
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();

        // For monthly/quarterly reports, also gather prior period data for comparisons
        $priorPeriodData = null;
        if (in_array($period, ['monthly', 'quarterly'], true)) {
            $priorPeriodData = $this->getPriorPeriodComparison($customer, $days);
        }

        $campaigns = Campaign::where('customer_id', $customer->id)
            ->where(function ($q) {
                $q->whereNotNull('google_ads_campaign_id')
                  ->orWhereNotNull('facebook_ads_campaign_id');
            })
            ->get();

        $googlePerformance = $this->getGooglePerformance($customer, $campaigns, $days);
        $facebookPerformance = $this->getFacebookPerformance($customer, $campaigns, $days);
        $keywordInsights = $this->getKeywordInsights($customer, $days);
        $hourlyInsights = $this->getHourlyInsights($customer, $days);
        $qsTrends = $this->trendingService->getTrends($customer, $days);

        $googleSummary = $this->calculateSummary($googlePerformance);
        $facebookSummary = $this->calculateFacebookSummary($facebookPerformance);
        $combinedSummary = $this->calculateCombinedSummary($googleSummary, $facebookSummary);

        $report = [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'period' => [
                'type' => $period,
                'start' => $startDate,
                'end' => $endDate,
                'days' => $days,
            ],
            'summary' => $combinedSummary,
            'google_summary' => $googleSummary,
            'facebook_summary' => $facebookSummary,
            'campaigns' => $googlePerformance,
            'facebook_campaigns' => $facebookPerformance,
            'keyword_insights' => $keywordInsights,
            'quality_score_trends' => $qsTrends,
            'hourly_insights' => $hourlyInsights,
            'prior_period' => $priorPeriodData,
            'agent_activity_summary' => $this->getAgentActivitySummary($customer, $days),
            'attribution_summary'    => $this->getAttributionSummary($customer, $startDate, $endDate),
            'generated_at' => now()->toIso8601String(),
        ];

        $report['ai_executive_summary'] = $this->generateNarrative($report);
        $report['ai_insights']          = $this->generateWoWInsights($report, $customer, $days);

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
        $googleSummary = $report['google_summary'];
        $facebookSummary = $report['facebook_summary'];

        $prompt = "You are a senior digital marketing strategist writing a {$period} executive summary for {$report['customer_name']}.\n\n"
            . "Combined Performance:\n"
            . "- Total Campaigns: {$summary['total_campaigns']}\n"
            . "- Impressions: " . number_format($summary['total_impressions']) . "\n"
            . "- Clicks: " . number_format($summary['total_clicks']) . "\n"
            . "- CTR: {$summary['blended_ctr']}%\n"
            . "- Total Spend: \${$summary['total_cost']}\n"
            . "- Conversions: {$summary['total_conversions']}\n"
            . "- CPA: \${$summary['blended_cpa']}\n"
            . "- Avg CPC: \${$summary['blended_cpc']}\n";

        if ($googleSummary['total_campaigns'] > 0) {
            $prompt .= "\nGoogle Ads:\n"
                . "- Campaigns: {$googleSummary['total_campaigns']}\n"
                . "- Spend: \${$googleSummary['total_cost']}\n"
                . "- Conversions: {$googleSummary['total_conversions']}\n"
                . "- CPA: \${$googleSummary['blended_cpa']}\n";
        }

        if ($facebookSummary['total_campaigns'] > 0) {
            $prompt .= "\nFacebook Ads:\n"
                . "- Campaigns: {$facebookSummary['total_campaigns']}\n"
                . "- Spend: \${$facebookSummary['total_cost']}\n"
                . "- Conversions: {$facebookSummary['total_conversions']}\n"
                . "- CPA: \${$facebookSummary['blended_cpa']}\n"
                . "- Reach: " . number_format($facebookSummary['total_reach']) . "\n"
                . "- Frequency: {$facebookSummary['avg_frequency']}\n";
        }

        if ($kwInsights['average_qs']) {
            $prompt .= "\nKeyword Quality:\n"
                . "- Average Quality Score: {$kwInsights['average_qs']}/10\n"
                . "- Keywords tracked: {$kwInsights['total_keywords_tracked']}\n"
                . "- High QS keywords (7+): {$kwInsights['high_qs_keywords']}\n"
                . "- Low QS keywords (<5): {$kwInsights['low_qs_keywords']}\n";
        }

        $prompt .= "\nWrite a concise 3-4 paragraph executive summary covering: 1) Overall cross-platform performance, 2) Platform-specific highlights and concerns, 3) Key wins, 4) Recommended next steps. Be data-driven and specific.";

        // Include agent activity context for richer narrative
        $agentSummary = $report['agent_activity_summary'] ?? null;
        if ($agentSummary && $agentSummary['total_actions'] > 0) {
            $prompt .= "\n\nAutonomous Agent Activity This Period ({$agentSummary['total_actions']} total actions, {$agentSummary['completed']} completed):";
            foreach ($agentSummary['by_agent'] ?? [] as $agent) {
                $prompt .= "\n- {$agent['agent']} Agent: {$agent['total_actions']} actions";
                foreach ($agent['key_actions'] as $action) {
                    $prompt .= "\n  • {$action['description']}";
                }
            }
            $prompt .= "\n\nIMPORTANT: In your summary, explain how these autonomous agent actions impacted the performance metrics. "
                . "For example, 'CPA improved because the Search Term Mining agent added X negative keywords' or "
                . "'Budget utilization improved after the Budget Intelligence agent shifted spend to peak hours.' "
                . "This helps the client understand the value of the autonomous optimization.";
        }

        try {
            $result = $this->gemini->generateContent(
                'gemini-3-flash-preview',
                $prompt,
                ['temperature' => 0.3, 'maxOutputTokens' => 1024],
                'You are a professional digital marketing analyst writing executive reports for business stakeholders.'
            );

            return $result['text'] ?? null;
        } catch (\Exception $e) {
            Log::warning("Failed to generate AI narrative: " . $e->getMessage());
            return null;
        }
    }

    protected function getFacebookPerformance(Customer $customer, $campaigns, int $days): array
    {
        $results = [];
        $startDate = now()->subDays($days)->toDateString();

        foreach ($campaigns as $campaign) {
            if (!$campaign->facebook_ads_campaign_id) {
                continue;
            }

            $perfData = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', '>=', $startDate)
                ->get();

            if ($perfData->isNotEmpty()) {
                $results[] = [
                    'campaign_name' => $campaign->name,
                    'campaign_id' => $campaign->id,
                    'impressions' => $perfData->sum('impressions'),
                    'clicks' => $perfData->sum('clicks'),
                    'cost' => round($perfData->sum('cost'), 2),
                    'conversions' => $perfData->sum('conversions'),
                    'reach' => $perfData->sum('reach'),
                    'avg_frequency' => $perfData->avg('frequency') ? round($perfData->avg('frequency'), 2) : 0,
                    'cpc' => $perfData->avg('cpc') ? round($perfData->avg('cpc'), 2) : 0,
                    'cpm' => $perfData->avg('cpm') ? round($perfData->avg('cpm'), 2) : 0,
                    'cpa' => $perfData->avg('cpa') ? round($perfData->avg('cpa'), 2) : 0,
                ];
            }
        }

        return $results;
    }

    protected function calculateFacebookSummary(array $facebookPerformance): array
    {
        $totalImpressions = 0;
        $totalClicks = 0;
        $totalCost = 0;
        $totalConversions = 0;
        $totalReach = 0;
        $totalFrequency = 0;

        foreach ($facebookPerformance as $campaign) {
            $totalImpressions += $campaign['impressions'] ?? 0;
            $totalClicks += $campaign['clicks'] ?? 0;
            $totalCost += $campaign['cost'] ?? 0;
            $totalConversions += $campaign['conversions'] ?? 0;
            $totalReach += $campaign['reach'] ?? 0;
            $totalFrequency += $campaign['avg_frequency'] ?? 0;
        }

        $count = count($facebookPerformance);

        return [
            'total_campaigns' => $count,
            'total_impressions' => $totalImpressions,
            'total_clicks' => $totalClicks,
            'total_cost' => round($totalCost, 2),
            'total_conversions' => $totalConversions,
            'total_reach' => $totalReach,
            'avg_frequency' => $count > 0 ? round($totalFrequency / $count, 2) : 0,
            'blended_ctr' => $totalImpressions > 0 ? round($totalClicks / $totalImpressions * 100, 2) : 0,
            'blended_cpc' => $totalClicks > 0 ? round($totalCost / $totalClicks, 2) : 0,
            'blended_cpa' => $totalConversions > 0 ? round($totalCost / $totalConversions, 2) : 0,
        ];
    }

    protected function calculateCombinedSummary(array $googleSummary, array $facebookSummary): array
    {
        $totalImpressions = $googleSummary['total_impressions'] + $facebookSummary['total_impressions'];
        $totalClicks = $googleSummary['total_clicks'] + $facebookSummary['total_clicks'];
        $totalCost = $googleSummary['total_cost'] + $facebookSummary['total_cost'];
        $totalConversions = $googleSummary['total_conversions'] + $facebookSummary['total_conversions'];

        return [
            'total_campaigns' => $googleSummary['total_campaigns'] + $facebookSummary['total_campaigns'],
            'total_impressions' => $totalImpressions,
            'total_clicks' => $totalClicks,
            'total_cost' => round($totalCost, 2),
            'total_conversions' => $totalConversions,
            'blended_ctr' => $totalImpressions > 0 ? round($totalClicks / $totalImpressions * 100, 2) : 0,
            'blended_cpc' => $totalClicks > 0 ? round($totalCost / $totalClicks, 2) : 0,
            'blended_cpa' => $totalConversions > 0 ? round($totalCost / $totalConversions, 2) : 0,
            'platforms' => array_filter([
                $googleSummary['total_campaigns'] > 0 ? 'google' : null,
                $facebookSummary['total_campaigns'] > 0 ? 'facebook' : null,
            ]),
        ];
    }

    /**
     * Get prior period comparison data for month-over-month analysis.
     */
    protected function getPriorPeriodComparison(Customer $customer, int $days): array
    {
        $priorStart = now()->subDays($days * 2)->toDateString();
        $priorEnd = now()->subDays($days)->toDateString();

        $campaignIds = Campaign::where('customer_id', $customer->id)->pluck('id');

        $priorGoogle = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->whereBetween('date', [$priorStart, $priorEnd])
            ->get();

        $priorFacebook = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->whereBetween('date', [$priorStart, $priorEnd])
            ->get();

        $priorSpend = round($priorGoogle->sum('cost') + $priorFacebook->sum('cost'), 2);
        $priorClicks = $priorGoogle->sum('clicks') + $priorFacebook->sum('clicks');
        $priorConversions = $priorGoogle->sum('conversions') + $priorFacebook->sum('conversions');
        $priorImpressions = $priorGoogle->sum('impressions') + $priorFacebook->sum('impressions');

        return [
            'period' => ['start' => $priorStart, 'end' => $priorEnd],
            'total_cost' => $priorSpend,
            'total_clicks' => $priorClicks,
            'total_conversions' => $priorConversions,
            'total_impressions' => $priorImpressions,
            'blended_ctr' => $priorImpressions > 0 ? round($priorClicks / $priorImpressions * 100, 2) : 0,
            'blended_cpc' => $priorClicks > 0 ? round($priorSpend / $priorClicks, 2) : 0,
            'blended_cpa' => $priorConversions > 0 ? round($priorSpend / $priorConversions, 2) : 0,
        ];
    }

    /**
     * Get agent activity summary for the report period.
     */
    protected function getAgentActivitySummary(Customer $customer, int $days): array
    {
        $activities = AgentActivity::where('customer_id', $customer->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->get();

        $byType = $activities->groupBy('agent_type')->map(function ($group, $type) {
            return [
                'agent' => $type,
                'total_actions' => $group->count(),
                'completed' => $group->where('status', 'completed')->count(),
                'failed' => $group->where('status', 'failed')->count(),
                'key_actions' => $group->where('status', 'completed')
                    ->sortByDesc('created_at')
                    ->take(3)
                    ->map(fn($a) => [
                        'action' => $a->action,
                        'description' => $a->description,
                        'date' => $a->created_at->toDateTimeString(),
                    ])->values()->toArray(),
            ];
        })->values()->toArray();

        return [
            'total_actions' => $activities->count(),
            'completed' => $activities->where('status', 'completed')->count(),
            'by_agent' => $byType,
        ];
    }

    /**
     * Generate structured WoW bullet insights for the current vs prior period.
     *
     * Returns an array of bullets like:
     *   ['metric' => 'CPA', 'change' => -18, 'direction' => 'improved', 'insight' => '...']
     *
     * These are rendered above the data tables in WeeklyExecutiveReport.
     */
    protected function generateWoWInsights(array $report, Customer $customer, int $days): array
    {
        // Build prior period on-the-fly for weekly reports (monthly already has it)
        $prior = $report['prior_period'] ?? $this->getPriorPeriodComparison($customer, $days);

        if (!$prior || ($prior['total_impressions'] ?? 0) === 0) {
            return [];
        }

        $current = $report['summary'];

        // Compute deltas
        $metrics = [
            'spend'       => ['current' => $current['total_cost'],        'prior' => $prior['total_cost'],        'label' => 'Total Spend',       'higher_is' => 'neutral'],
            'impressions' => ['current' => $current['total_impressions'],  'prior' => $prior['total_impressions'],  'label' => 'Impressions',       'higher_is' => 'good'],
            'clicks'      => ['current' => $current['total_clicks'],       'prior' => $prior['total_clicks'],       'label' => 'Clicks',            'higher_is' => 'good'],
            'ctr'         => ['current' => $current['blended_ctr'],        'prior' => $prior['blended_ctr'],        'label' => 'CTR',               'higher_is' => 'good'],
            'cpc'         => ['current' => $current['blended_cpc'],        'prior' => $prior['blended_cpc'],        'label' => 'CPC',               'higher_is' => 'bad'],
            'conversions' => ['current' => $current['total_conversions'],  'prior' => $prior['total_conversions'],  'label' => 'Conversions',       'higher_is' => 'good'],
            'cpa'         => ['current' => $current['blended_cpa'],        'prior' => $prior['blended_cpa'],        'label' => 'CPA',               'higher_is' => 'bad'],
        ];

        $movers = [];
        foreach ($metrics as $key => $m) {
            if (($m['prior'] ?? 0) <= 0) {
                continue;
            }
            $changePct = round(($m['current'] - $m['prior']) / $m['prior'] * 100, 1);
            if (abs($changePct) < 5) {
                continue; // Skip flat metrics — not worth reporting
            }

            $direction = match (true) {
                $changePct > 0 && $m['higher_is'] === 'good' => 'improved',
                $changePct < 0 && $m['higher_is'] === 'bad'  => 'improved',
                $changePct > 0 && $m['higher_is'] === 'bad'  => 'declined',
                $changePct < 0 && $m['higher_is'] === 'good' => 'declined',
                default => 'changed',
            };

            $movers[] = [
                'metric'    => $key,
                'label'     => $m['label'],
                'current'   => $m['current'],
                'prior'     => $m['prior'],
                'change'    => $changePct,
                'direction' => $direction,
            ];
        }

        if (empty($movers)) {
            return [];
        }

        // Sort by absolute change descending — biggest movers first
        usort($movers, fn($a, $b) => abs($b['change']) <=> abs($a['change']));
        $movers = array_slice($movers, 0, 5);

        // Ask Gemini to add likely cause + action for each mover
        return $this->enrichMoversWithInsights($movers, $report['customer_name'], $report['period']['type']);
    }

    private function enrichMoversWithInsights(array $movers, string $customerName, string $period): array
    {
        $moveSummary = implode("\n", array_map(fn($m) =>
            "- {$m['label']}: {$m['change']}% ({$m['direction']}) — was {$m['prior']}, now {$m['current']}",
        $movers));

        $prompt = <<<PROMPT
You are a senior SEM analyst writing a {$period} performance brief for {$customerName}.

These metrics moved significantly vs the prior period:
{$moveSummary}

For each metric, write ONE bullet in this exact format:
• [Metric] [changed X%]: [likely cause in ≤8 words] → [recommended action in ≤8 words]

Return ONLY a JSON array of strings, one per metric, in the same order:
["• CPA improved 18%: ...", "• CTR declined 12%: ..."]
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                'gemini-2.0-flash',
                $prompt,
                ['temperature' => 0.3, 'maxOutputTokens' => 400]
            );
            $text    = preg_replace('/```json\s*|\s*```/', '', $response['text'] ?? '');
            $bullets = json_decode(trim($text), true);

            if (is_array($bullets) && !empty($bullets)) {
                foreach ($movers as $i => $mover) {
                    $movers[$i]['insight'] = $bullets[$i] ?? null;
                }
            }
        } catch (\Exception $e) {
            Log::debug("ExecutiveReportService: WoW insight generation failed: " . $e->getMessage());
        }

        return $movers;
    }

    /**
     * Build an attribution summary from AttributionConversion records for the report period.
     *
     * Returns per-model (last_click, linear, time_decay) conversion counts and value per platform.
     */
    protected function getAttributionSummary(Customer $customer, string $startDate, string $endDate): array
    {
        try {
            $conversions = AttributionConversion::forCustomer($customer->id)
                ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->get();

            if ($conversions->isEmpty()) {
                return [];
            }

            $models   = ['last_click', 'linear', 'time_decay', 'first_click', 'position_based'];
            $summary  = [];

            foreach ($models as $model) {
                $byPlatform = [];
                foreach ($conversions as $conv) {
                    $attribution = $conv->getAttributionFor($model);
                    foreach ($attribution as $platform => $value) {
                        $byPlatform[$platform]['conversions'] = ($byPlatform[$platform]['conversions'] ?? 0) + 1;
                        $byPlatform[$platform]['value']       = ($byPlatform[$platform]['value'] ?? 0) + (float) $value;
                    }
                }
                if (!empty($byPlatform)) {
                    $summary[$model] = $byPlatform;
                }
            }

            return $summary;
        } catch (\Exception $e) {
            Log::debug("ExecutiveReportService: Attribution summary failed: " . $e->getMessage());
            return [];
        }
    }
}
