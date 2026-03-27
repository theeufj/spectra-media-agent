<?php

namespace App\Services;

use App\Models\ABTest;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\FacebookAds\InsightService;
use Illuminate\Support\Facades\Log;

/**
 * CopilotContextService
 *
 * Gathers comprehensive campaign context for the AI Copilot conversations.
 * Collects performance data, strategies, A/B tests, recommendations, and more.
 */
class CopilotContextService
{
    /**
     * Build full context for a campaign copilot conversation.
     */
    public function buildContext(Campaign $campaign): array
    {
        $campaign->load(['customer', 'strategies']);

        $context = [
            'campaign' => $this->getCampaignDetails($campaign),
            'strategies' => $this->getStrategyDetails($campaign),
            'performance' => $this->getPerformanceData($campaign),
            'ab_tests' => $this->getABTestData($campaign),
            'optimization' => $this->getOptimizationData($campaign),
        ];

        return $context;
    }

    /**
     * Format context into a string for the AI prompt.
     */
    public function formatContextForPrompt(array $context): string
    {
        $sections = [];

        // Campaign overview
        $c = $context['campaign'];
        $sections[] = "## Campaign Overview\n" .
            "Name: {$c['name']}\n" .
            "Product Focus: {$c['product_focus']}\n" .
            "Total Budget: \${$c['total_budget']}\n" .
            "Daily Budget: \${$c['daily_budget']}\n" .
            "Primary KPI: {$c['primary_kpi']}\n" .
            "Goals: " . implode(', ', $c['goals'] ?? []) . "\n" .
            "Target Market: {$c['target_market']}\n" .
            "Start Date: {$c['start_date']}\n" .
            "End Date: {$c['end_date']}";

        // Strategies
        if (!empty($context['strategies'])) {
            $stratSection = "## Active Strategies";
            foreach ($context['strategies'] as $strat) {
                $stratSection .= "\n\n### {$strat['platform']} — {$strat['campaign_type']}\n" .
                    "Status: {$strat['status']}\n" .
                    "Ad Copy: " . mb_substr($strat['ad_copy_strategy'] ?? 'N/A', 0, 200) . "\n" .
                    "Bidding: " . json_encode($strat['bidding_strategy'] ?? []);
            }
            $sections[] = $stratSection;
        }

        // Performance data
        if (!empty($context['performance'])) {
            $perf = $context['performance'];
            $platform = $perf['platform'] ?? 'Unknown';
            $metrics = $perf['current'] ?? [];
            $historical = $perf['historical'] ?? [];

            $perfSection = "## Performance Data ({$platform})";
            if (!empty($metrics)) {
                $perfSection .= "\n\nLast 30 Days:";
                foreach ($metrics as $key => $val) {
                    $perfSection .= "\n- {$key}: {$val}";
                }
            }
            if (!empty($historical)) {
                $perfSection .= "\n\nPrevious 30 Days:";
                foreach ($historical as $key => $val) {
                    $perfSection .= "\n- {$key}: {$val}";
                }
            }
            $sections[] = $perfSection;
        }

        // A/B Tests
        if (!empty($context['ab_tests'])) {
            $abSection = "## A/B Tests";
            foreach ($context['ab_tests'] as $test) {
                $abSection .= "\n\n### {$test['test_type']} test (Status: {$test['status']})\n" .
                    "Started: {$test['started_at']}\n" .
                    "Confidence: " . ($test['confidence_level'] ? round($test['confidence_level'] * 100, 1) . '%' : 'N/A');
                if ($test['winning_variant_id']) {
                    $abSection .= "\nWinner: {$test['winning_variant_id']}";
                }
                foreach ($test['variants'] ?? [] as $v) {
                    $imp = $v['impressions'] ?? 0;
                    $clicks = $v['clicks'] ?? 0;
                    $ctr = $imp > 0 ? round(($clicks / $imp) * 100, 2) : 0;
                    $abSection .= "\n- {$v['label']}: {$imp} impr, {$clicks} clicks, {$ctr}% CTR";
                }
            }
            $sections[] = $abSection;
        }

        // Optimization insights
        if (!empty($context['optimization'])) {
            $opt = $context['optimization'];
            $optSection = "## Latest Optimization Analysis";
            if (isset($opt['analysis'])) {
                $optSection .= "\n" . mb_substr($opt['analysis'], 0, 500);
            }
            if (!empty($opt['recommendations'])) {
                $optSection .= "\n\nTop Recommendations:";
                foreach (array_slice($opt['recommendations'], 0, 5) as $rec) {
                    $optSection .= "\n- [{$rec['type']}] {$rec['description']} (Impact: {$rec['impact']}, Risk: {$rec['risk_level']})";
                }
            }
            $sections[] = $optSection;
        }

        return implode("\n\n---\n\n", $sections);
    }

    protected function getCampaignDetails(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'product_focus' => $campaign->product_focus ?? 'N/A',
            'total_budget' => $campaign->total_budget ?? 0,
            'daily_budget' => $campaign->daily_budget ?? 0,
            'primary_kpi' => $campaign->primary_kpi ?? 'N/A',
            'goals' => $campaign->goals ?? [],
            'target_market' => $campaign->target_market ?? 'N/A',
            'start_date' => $campaign->start_date?->format('Y-m-d') ?? 'N/A',
            'end_date' => $campaign->end_date?->format('Y-m-d') ?? 'N/A',
            'platform' => $campaign->google_ads_campaign_id ? 'Google Ads' : ($campaign->facebook_ads_campaign_id ? 'Facebook Ads' : 'Not deployed'),
            'geographic_targeting' => $campaign->geographic_targeting ?? [],
        ];
    }

    protected function getStrategyDetails(Campaign $campaign): array
    {
        return $campaign->strategies->map(function ($strategy) {
            return [
                'id' => $strategy->id,
                'platform' => $strategy->platform,
                'campaign_type' => $strategy->campaign_type,
                'ad_copy_strategy' => $strategy->ad_copy_strategy,
                'imagery_strategy' => $strategy->imagery_strategy,
                'bidding_strategy' => $strategy->bidding_strategy,
                'status' => $strategy->deployment_status ?? ($strategy->signed_off_at ? 'signed_off' : 'draft'),
                'signed_off_at' => $strategy->signed_off_at?->toIso8601String(),
            ];
        })->toArray();
    }

    protected function getPerformanceData(Campaign $campaign): array
    {
        $customer = $campaign->customer;
        if (!$customer) {
            return [];
        }

        try {
            if ($campaign->google_ads_campaign_id) {
                $service = app(GetCampaignPerformance::class, ['customer' => $customer, 'useMcc' => true]);
                $customerId = $customer->google_ads_customer_id;
                $resourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

                return [
                    'platform' => 'Google Ads',
                    'current' => $service($customerId, $resourceName, 'LAST_30_DAYS'),
                    'historical' => $service($customerId, $resourceName, 'LAST_30_DAYS'), // Ideally previous period
                ];
            }

            if ($campaign->facebook_ads_campaign_id) {
                $service = new InsightService($customer);
                $current = $service->getCampaignInsights(
                    $campaign->facebook_ads_campaign_id,
                    now()->subDays(30)->format('Y-m-d'),
                    now()->format('Y-m-d')
                );
                $historical = $service->getCampaignInsights(
                    $campaign->facebook_ads_campaign_id,
                    now()->subDays(60)->format('Y-m-d'),
                    now()->subDays(30)->format('Y-m-d')
                );

                return [
                    'platform' => 'Facebook Ads',
                    'current' => $current,
                    'historical' => $historical,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('CopilotContextService: Failed to fetch performance data', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    protected function getABTestData(Campaign $campaign): array
    {
        return ABTest::forCampaign($campaign->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    protected function getOptimizationData(Campaign $campaign): array
    {
        return $campaign->latest_optimization_analysis ?? [];
    }
}
