<?php

namespace App\Services\Testing;

use App\Contracts\Ads\AdsServiceFactory;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\KeywordQualityScore;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Agents\HealthCheckAgent;
use App\Services\Agents\Optimization\MetricsFetcher;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\Agents\Optimization\RecommendationScorer;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\Testing\Sandbox\SandboxCampaignPerformanceSource;
use App\Services\Testing\Sandbox\SandboxGeminiService;
use Illuminate\Support\Facades\Log;

/**
 * SandboxAgentRunner — Analyses sandbox data using DB queries only.
 *
 * Real agents call external ad-platform APIs (Google Ads, Facebook, etc.)
 * which fail for sandbox campaign IDs. This runner reads the synthetic
 * performance data already seeded in the database and generates
 * scenario-aware recommendations that mirror what each agent would produce.
 */
class SandboxAgentRunner
{
    protected array $results = [];

    /**
     * Run all analysis agents against sandbox data and collect results.
     */
    public function runAll(Customer $customer): array
    {
        Log::info('SandboxAgentRunner: Starting analysis', ['customer_id' => $customer->id]);

        $this->results = [];
        $campaigns = $customer->campaigns()->get();

        // 1. Customer-level: Health Check
        $this->runHealthCheck($customer, $campaigns);

        // 2. Campaign-level agents
        foreach ($campaigns as $campaign) {
            $platform = $this->detectPlatform($campaign);
            $perf = $this->getCampaignPerformance($campaign, $platform);

            $this->runAlerts($campaign, $customer, $perf);
            $this->runOptimization($campaign, $customer, $perf, $platform);
            $this->runBudgetIntelligence($campaign, $customer, $perf);

            if (in_array($platform, ['google', 'microsoft'])) {
                $this->runSearchTermMining($campaign, $customer);
            }

            $this->runCreativeIntelligence($campaign, $customer, $perf, $platform);
            $this->runSelfHealing($campaign, $customer, $perf, $platform);
        }

        Log::info('SandboxAgentRunner: Analysis complete', [
            'customer_id' => $customer->id,
            'result_count' => count($this->results),
        ]);

        return $this->results;
    }

    // ── Health Check ───────────────────────────────────────────────

    /**
     * Runs the REAL HealthCheckAgent against synthetic data.
     *
     * Its connectivity probe and account-status lookup were the last live API
     * calls in this path; both now resolve through AdsServiceFactory, so a
     * sandbox customer reaches the DB-driven checks that make up the bulk of
     * the agent instead of failing at the first step.
     *
     * Gemini is stubbed so the run is reproducible.
     */
    protected function runHealthCheck(Customer $customer, $campaigns): void
    {
        try {
            $agent = app()->makeWith(HealthCheckAgent::class, ['gemini' => new SandboxGeminiService]);
            $result = $agent->checkCustomerHealth($customer);

            $this->results['HealthCheckAgent'] = ['status' => 'completed', 'data' => $result];

            AgentActivity::record(
                'HealthCheckAgent',
                'sandbox_health_check',
                sprintf(
                    'Sandbox: health %s — %d issue(s), %d warning(s)',
                    $result['overall_health'] ?? 'unknown',
                    count($result['issues'] ?? []),
                    count($result['warnings'] ?? [])
                ),
                $customer->id,
                null,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure('HealthCheckAgent', $customer->id, null, $e);
        }
    }

    protected function getHealthRecommendations(array $summaries): array
    {
        $recs = [];
        foreach ($summaries as $s) {
            if ($s['health'] === 'critical') {
                $recs[] = "🚨 {$s['campaign']}: Zero conversions with active spend. Pause and investigate landing page or conversion tracking.";
            }
            if ($s['cpa'] > 100) {
                $recs[] = "⚠️ {$s['campaign']}: CPA is \${$s['cpa']} — consider tightening targeting or raising bids on high-converting segments.";
            }
            if ($s['roas'] < 1.0 && $s['roas'] > 0) {
                $recs[] = "⚠️ {$s['campaign']}: ROAS below 1.0x — campaign is losing money. Review audience and ad relevance.";
            }
            if ($s['health'] === 'healthy') {
                $recs[] = "✅ {$s['campaign']}: Performing well at \${$s['cpa']} CPA and {$s['roas']}x ROAS.";
            }
        }

        return $recs;
    }

    // ── Alerts ─────────────────────────────────────────────────────

    protected function runAlerts(Campaign $campaign, Customer $customer, array $perf): void
    {
        try {
            $alerts = [];

            if ($perf['cost'] > $campaign->daily_budget * 30 * 1.15) {
                $alerts[] = [
                    'type' => 'budget_overspend',
                    'severity' => 'high',
                    'message' => "Spending {$perf['budget_utilization']}% of budget. Consider reducing bids or narrowing targeting.",
                ];
            }

            if ($perf['cpa'] > 80) {
                $alerts[] = [
                    'type' => 'high_cpa',
                    'severity' => $perf['cpa'] > 150 ? 'critical' : 'medium',
                    'message' => "CPA is \${$perf['cpa']} — significantly above efficient levels. Review conversion path.",
                ];
            }

            if ($perf['ctr'] < 0.01) {
                $alerts[] = [
                    'type' => 'low_ctr',
                    'severity' => 'medium',
                    'message' => 'CTR is '.round($perf['ctr'] * 100, 2).'% — ad relevance may be poor. Test new creative.',
                ];
            }

            if ($perf['trend_direction'] === 'declining') {
                $alerts[] = [
                    'type' => 'declining_performance',
                    'severity' => 'high',
                    'message' => 'Performance declining over last 7 days. Investigate audience fatigue or market shifts.',
                ];
            }

            if (empty($alerts)) {
                $alerts[] = [
                    'type' => 'all_clear',
                    'severity' => 'info',
                    'message' => 'No alerts — campaign performing within expected parameters.',
                ];
            }

            $key = "CampaignAlertService_{$campaign->id}";
            $this->results[$key] = ['status' => 'completed', 'campaign' => $campaign->name, 'data' => $alerts];

            AgentActivity::record(
                'CampaignAlertService',
                'sandbox_alert_check',
                'Sandbox: Found '.count($alerts)." alerts for {$campaign->name}",
                $customer->id,
                $campaign->id,
                $alerts,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure('CampaignAlertService', $customer->id, $campaign->id, $e);
        }
    }

    // ── Optimization ───────────────────────────────────────────────

    /**
     * Runs the REAL CampaignOptimizationAgent against synthetic data.
     *
     * Dependencies are passed explicitly rather than rebinding the container:
     * a global override can leak past the run that set it, and this used to be
     * a reimplementation precisely because nobody wanted that risk. Building the
     * agent by hand keeps the substitution scoped to this call.
     *
     * Gemini is stubbed for determinism — a real model call would return
     * something different every run, so a changed sandbox result would tell you
     * nothing about whether behaviour changed.
     */
    protected function runOptimization(Campaign $campaign, Customer $customer, array $perf, string $platform): void
    {
        try {
            $agent = new CampaignOptimizationAgent(
                new SandboxGeminiService,
                new MetricsFetcher(new SandboxCampaignPerformanceSource($customer)),
                app(RecommendationScorer::class),
                app(RecommendationApplier::class),
            );

            $result = $agent->analyze($campaign) ?? ['recommendations' => []];

            $key = "CampaignOptimizationAgent_{$campaign->id}";
            $this->results[$key] = ['status' => 'completed', 'campaign' => $campaign->name, 'data' => $result];

            AgentActivity::record(
                'CampaignOptimizationAgent',
                'sandbox_optimization',
                sprintf(
                    'Sandbox: %d recommendation(s) generated for %s',
                    count($result['recommendations'] ?? []),
                    $campaign->name
                ),
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure('CampaignOptimizationAgent', $customer->id, $campaign->id, $e);
        }
    }

    // ── Budget Intelligence ────────────────────────────────────────

    /**
     * Runs the REAL BudgetIntelligenceAgent against synthetic data.
     *
     * Reads the seeded GoogleAdsPerformanceData rows through the sandbox
     * performance source and records any budget change instead of sending it,
     * so pacing and reallocation decisions are visible without real money
     * being able to move.
     */
    protected function runBudgetIntelligence(Campaign $campaign, Customer $customer, array $perf): void
    {
        try {
            $result = app(BudgetIntelligenceAgent::class)->optimize($campaign);
            $result['budget_changes'] = app(AdsServiceFactory::class)->recordedBudgetChanges($customer);

            $key = "BudgetIntelligenceAgent_{$campaign->id}";
            $this->results[$key] = ['status' => 'completed', 'campaign' => $campaign->name, 'data' => $result];

            AgentActivity::record(
                'BudgetIntelligenceAgent',
                'sandbox_budget_intelligence',
                sprintf(
                    'Sandbox: budget analysis for %s — %d change(s) proposed',
                    $campaign->name,
                    count($result['budget_changes'])
                ),
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure('BudgetIntelligenceAgent', $customer->id, $campaign->id, $e);
        }
    }

    // ── Search Term Mining ─────────────────────────────────────────

    /**
     * Runs the REAL SearchTermMiningAgent against synthetic data.
     *
     * This method previously reimplemented the agent: it derived its own
     * recommendations from KeywordQualityScore rows and recorded them as
     * AgentActivity under the name "SearchTermMiningAgent". The real agent was
     * never invoked, so a green sandbox run was evidence of nothing — worse, it
     * manufactured activity records implying an agent had worked when it had
     * never executed once in production.
     *
     * The agent now resolves its platform services through AdsServiceFactory,
     * which returns synthetic ones for sandbox customers, so the genuine
     * thresholds, match-type selection and safety rules all execute here.
     */
    protected function runSearchTermMining(Campaign $campaign, Customer $customer): void
    {
        try {
            $result = app(SearchTermMiningAgent::class)->mine($campaign);

            $result['decisions'] = app(AdsServiceFactory::class)->recordedDecisions($customer);

            $key = "SearchTermMiningAgent_{$campaign->id}";
            $this->results[$key] = ['status' => 'completed', 'campaign' => $campaign->name, 'data' => $result];

            AgentActivity::record(
                'SearchTermMiningAgent',
                'sandbox_search_mining',
                sprintf(
                    'Sandbox: analysed %d search terms — %d keyword(s) promoted, %d negative(s) added for %s',
                    $result['terms_analyzed'] ?? 0,
                    count($result['keywords_added'] ?? []),
                    count($result['negatives_added'] ?? []),
                    $campaign->name
                ),
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure('SearchTermMiningAgent', $customer->id, $campaign->id, $e);
        }
    }

    // ── Creative Intelligence ──────────────────────────────────────

    protected function runCreativeIntelligence(Campaign $campaign, Customer $customer, array $perf, string $platform): void
    {
        try {
            $insights = [];

            // CTR-based creative assessment
            $platformAvgCtr = match ($platform) {
                'facebook' => 0.015,
                'linkedin' => 0.005,
                'microsoft' => 0.04,
                default => 0.05,
            };

            $ctrPerformance = $perf['ctr'] / max($platformAvgCtr, 0.001);

            if ($ctrPerformance < 0.6) {
                $insights[] = [
                    'type' => 'creative_fatigue',
                    'severity' => 'high',
                    'finding' => 'CTR is '.round($perf['ctr'] * 100, 2)."%, significantly below {$platform} average of ".round($platformAvgCtr * 100, 1).'%.',
                    'recommendation' => 'Test 3-5 new ad variations with different headlines, descriptions, and calls-to-action. Rotate creative every 2 weeks.',
                ];
            } elseif ($ctrPerformance < 0.9) {
                $insights[] = [
                    'type' => 'optimization_opportunity',
                    'severity' => 'medium',
                    'finding' => 'CTR is slightly below average. Small improvements can compound over time.',
                    'recommendation' => 'A/B test headlines focusing on urgency, social proof, and unique value props.',
                ];
            } else {
                $insights[] = [
                    'type' => 'strong_creative',
                    'severity' => 'info',
                    'finding' => 'CTR of '.round($perf['ctr'] * 100, 2)."% is at or above {$platform} benchmarks.",
                    'recommendation' => 'Creative performing well. Test incremental variations to prevent future fatigue.',
                ];
            }

            // Conversion rate vs CTR gap
            if ($perf['ctr'] > 0.03 && $perf['conv_rate'] < 0.01) {
                $insights[] = [
                    'type' => 'landing_page_disconnect',
                    'severity' => 'high',
                    'finding' => 'High CTR but low conversion rate suggests a disconnect between ad promise and landing page experience.',
                    'recommendation' => 'Review landing page messaging alignment, load speed, and mobile experience. Consider dedicated landing pages per ad group.',
                ];
            }

            // Platform-specific insights
            if ($platform === 'facebook' && $perf['cpa'] > 50) {
                $insights[] = [
                    'type' => 'audience_creative_mismatch',
                    'severity' => 'medium',
                    'finding' => 'High CPA on Facebook often indicates audience-creative mismatch or retargeting saturation.',
                    'recommendation' => 'Refresh creative assets, expand lookalike audiences, and check frequency caps. Consider UGC-style video ads.',
                ];
            }

            if ($platform === 'linkedin' && $perf['ctr'] < 0.005) {
                $insights[] = [
                    'type' => 'b2b_creative_strategy',
                    'severity' => 'medium',
                    'finding' => 'LinkedIn CTR is competitive but could benefit from thought leadership approach.',
                    'recommendation' => 'Test document ads, carousel formats, and single-image ads with strong industry statistics. Lead with insight, not product.',
                ];
            }

            $result = [
                'creative_health_score' => round(min(100, max(10, $ctrPerformance * 70 + ($perf['conv_rate'] > 0.02 ? 20 : 0)))),
                'insights' => $insights,
                'metrics_reviewed' => [
                    'ctr' => round($perf['ctr'] * 100, 2).'%',
                    'conv_rate' => round($perf['conv_rate'] * 100, 2).'%',
                    'cpa' => '$'.round($perf['cpa'], 2),
                ],
            ];

            $key = "CreativeIntelligenceAgent_{$campaign->id}";
            $this->results[$key] = ['status' => 'completed', 'campaign' => $campaign->name, 'data' => $result];

            AgentActivity::record(
                'CreativeIntelligenceAgent',
                'sandbox_creative_analysis',
                "Sandbox: Creative score {$result['creative_health_score']}/100 — ".count($insights)." insights for {$campaign->name}",
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure('CreativeIntelligenceAgent', $customer->id, $campaign->id, $e);
        }
    }

    // ── Self-Healing ───────────────────────────────────────────────

    protected function runSelfHealing(Campaign $campaign, Customer $customer, array $perf, string $platform): void
    {
        try {
            $actions = [];
            $issuesDetected = 0;

            // Check for zero conversion periods
            $recentDays = $this->getRecentDailyPerformance($campaign, $platform, 7);
            $zeroDays = $recentDays->where('conversions', 0)->count();

            if ($zeroDays >= 3) {
                $issuesDetected++;
                $actions[] = [
                    'issue' => 'conversion_dead_zone',
                    'severity' => 'critical',
                    'detected' => "{$zeroDays} of last 7 days had zero conversions.",
                    'action_taken' => 'RECOMMENDED: Check conversion tracking tag is firing. Verify thank-you page URL matches goal. Inspect for pixel/tag errors.',
                    'would_auto_fix' => 'In live mode, agent would verify tag status and re-enable if disabled.',
                ];
            }

            // Spend without results
            if ($perf['cost'] > 500 && $perf['conversions'] < 3) {
                $issuesDetected++;
                $actions[] = [
                    'issue' => 'budget_waste',
                    'severity' => 'high',
                    'detected' => "Spent \${$perf['cost']} with only {$perf['conversions']} conversions.",
                    'action_taken' => 'RECOMMENDED: Pause lowest-performing ad groups. Reallocate budget to converting segments.',
                    'would_auto_fix' => 'In live mode, agent would automatically pause underperforming ad groups.',
                ];
            }

            // CPC spike detection
            $avgCpc = match ($platform) {
                'linkedin' => 8.0,
                'facebook' => 1.5,
                default => 2.0,
            };

            if ($perf['cpc'] > $avgCpc * 2) {
                $issuesDetected++;
                $actions[] = [
                    'issue' => 'cpc_spike',
                    'severity' => 'medium',
                    'detected' => "CPC of \${$perf['cpc']} is ".round($perf['cpc'] / $avgCpc, 1).'x the platform average.',
                    'action_taken' => 'RECOMMENDED: Check for competitor bidding activity. Consider switching to Target CPA or Maximize Conversions bid strategy.',
                    'would_auto_fix' => 'In live mode, agent would adjust bid strategy and set CPC caps.',
                ];
            }

            // Quality score issues (search platforms only)
            if (in_array($platform, ['google', 'microsoft'])) {
                $avgQs = KeywordQualityScore::where('customer_id', $customer->id)
                    ->where('campaign_google_id', $campaign->google_ads_campaign_id ?? $campaign->microsoft_ads_campaign_id)
                    ->where('recorded_at', '>=', now()->subDays(7))
                    ->avg('quality_score');

                if ($avgQs && $avgQs < 5) {
                    $issuesDetected++;
                    $actions[] = [
                        'issue' => 'low_quality_scores',
                        'severity' => 'high',
                        'detected' => 'Average quality score is '.round($avgQs, 1).'/10 — this inflates CPCs by 20-50%.',
                        'action_taken' => 'RECOMMENDED: Improve ad relevance (tighter ad group themes), landing page quality (speed, mobile, content match), and expected CTR (better headlines).',
                        'would_auto_fix' => 'In live mode, agent would generate new responsive search ads with improved keyword-to-ad alignment.',
                    ];
                }
            }

            if (empty($actions)) {
                $actions[] = [
                    'issue' => 'none',
                    'severity' => 'info',
                    'detected' => 'No critical issues detected in this campaign.',
                    'action_taken' => 'Campaign is running normally. Self-healing agent continues to monitor.',
                    'would_auto_fix' => null,
                ];
            }

            $result = [
                'issues_detected' => $issuesDetected,
                'actions' => $actions,
                'campaign_status' => $issuesDetected === 0 ? 'healthy' : ($issuesDetected <= 2 ? 'needs_attention' : 'critical'),
            ];

            $key = "SelfHealingAgent_{$campaign->id}";
            $this->results[$key] = ['status' => 'completed', 'campaign' => $campaign->name, 'data' => $result];

            AgentActivity::record(
                'SelfHealingAgent',
                'sandbox_self_healing',
                "Sandbox: {$issuesDetected} issues detected for {$campaign->name} — ".($issuesDetected === 0 ? 'all clear' : $actions[0]['issue']),
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure('SelfHealingAgent', $customer->id, $campaign->id, $e);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    protected function detectPlatform(Campaign $campaign): string
    {
        if ($campaign->google_ads_campaign_id) {
            return 'google';
        }
        if ($campaign->facebook_ads_campaign_id) {
            return 'facebook';
        }
        if ($campaign->microsoft_ads_campaign_id) {
            return 'microsoft';
        }
        if ($campaign->linkedin_campaign_id) {
            return 'linkedin';
        }

        return 'google';
    }

    /**
     * Aggregate 30-day performance from the platform-specific performance tables.
     */
    protected function getCampaignPerformance(Campaign $campaign, string $platform): array
    {
        $model = match ($platform) {
            'facebook' => FacebookAdsPerformanceData::class,
            'microsoft' => MicrosoftAdsPerformanceData::class,
            'linkedin' => LinkedInAdsPerformanceData::class,
            default => GoogleAdsPerformanceData::class,
        };

        $data = $model::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(30))
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as revenue')
            ->first();

        $impressions = (int) ($data->impressions ?? 0);
        $clicks = (int) ($data->clicks ?? 0);
        $cost = (float) ($data->cost ?? 0);
        $conversions = (int) ($data->conversions ?? 0);
        $revenue = (float) ($data->revenue ?? 0);

        $ctr = $impressions > 0 ? $clicks / $impressions : 0;
        $cpc = $clicks > 0 ? $cost / $clicks : 0;
        $convRate = $clicks > 0 ? $conversions / $clicks : 0;
        $cpa = $conversions > 0 ? $cost / $conversions : 0;
        $roas = $cost > 0 ? $revenue / $cost : 0;
        $budgetUtil = $campaign->daily_budget > 0 ? ($cost / ($campaign->daily_budget * 30)) * 100 : 0;

        // Determine trend from last 7 days vs prior 7 days
        $recent = $model::where('campaign_id', $campaign->id)
            ->whereBetween('date', [now()->subDays(7), now()])
            ->sum('conversions');
        $prior = $model::where('campaign_id', $campaign->id)
            ->whereBetween('date', [now()->subDays(14), now()->subDays(7)])
            ->sum('conversions');

        $trendDirection = $recent >= $prior ? 'stable' : 'declining';
        if ($prior > 0 && $recent < $prior * 0.7) {
            $trendDirection = 'declining';
        }
        if ($prior > 0 && $recent > $prior * 1.15) {
            $trendDirection = 'improving';
        }

        return compact(
            'impressions', 'clicks', 'cost', 'conversions', 'revenue',
            'ctr', 'cpc', 'convRate', 'cpa', 'roas', 'budgetUtil'
        ) + [
            'conv_rate' => $convRate,
            'budget_utilization' => $budgetUtil,
            'trend_direction' => $trendDirection,
        ];
    }

    protected function getRecentDailyPerformance(Campaign $campaign, string $platform, int $days)
    {
        $model = match ($platform) {
            'facebook' => FacebookAdsPerformanceData::class,
            'microsoft' => MicrosoftAdsPerformanceData::class,
            'linkedin' => LinkedInAdsPerformanceData::class,
            default => GoogleAdsPerformanceData::class,
        };

        return $model::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays($days))
            ->orderBy('date', 'desc')
            ->get();
    }

    protected function recordFailure(string $agentType, int $customerId, ?int $campaignId, \Throwable $e): void
    {
        $key = $campaignId ? "{$agentType}_{$campaignId}" : $agentType;

        $this->results[$key] = [
            'status' => 'error',
            'error' => $e->getMessage(),
        ];

        Log::warning("SandboxAgentRunner: {$agentType} failed", [
            'customer_id' => $customerId,
            'campaign_id' => $campaignId,
            'error' => $e->getMessage(),
        ]);

        AgentActivity::record(
            $agentType,
            'sandbox_error',
            "Sandbox: {$agentType} encountered an error — ".$e->getMessage(),
            $customerId,
            $campaignId,
            ['error' => $e->getMessage()],
            'failed'
        );
    }
}
