<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\CreativeBrief;
use App\Models\MicrosoftAdsPerformanceData;
use App\Notifications\CriticalAgentAlert;
use App\Services\MicrosoftAds\CampaignService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class MicrosoftAdsCampaignOptimizationAgent
{
    // Microsoft Ads industry-average CPC benchmarks by campaign type (USD)
    // Typically 20-35% lower than Google Ads equivalents
    private const CPC_BENCHMARKS = [
        'search' => ['low' => 0.50, 'high' => 4.00],
    ];

    // CPL benchmarks by campaign objective (aligned with LinkedIn for comparison)
    private const CPL_BENCHMARKS = [
        'conversions'   => ['low' => 20,  'high' => 100],
        'leads'         => ['low' => 30,  'high' => 150],
        'brand'         => ['low' => 2,   'high' => 15],
        'traffic'       => ['low' => 1,   'high' => 10],
    ];

    private const CPL_OVERSPEND_MULTIPLIER = 3.0;
    private const MIN_IMPRESSIONS_FOR_ANALYSIS = 500;
    private const CTR_DECLINE_THRESHOLD = 0.25; // 25% WoW decline

    public function analyze(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'platform'    => 'microsoft',
            'actions'     => [],
            'issues'      => [],
            'briefs'      => [],
        ];

        if (!$campaign->microsoft_ads_campaign_id) {
            return $results;
        }

        $this->checkBudgetPacing($campaign, $results);
        $this->checkCtrDecline($campaign, $results);
        $this->checkCplVsBenchmark($campaign, $results);
        $this->checkImpressionShare($campaign, $results);

        if (!empty($results['actions']) || !empty($results['issues'])) {
            AgentActivity::create([
                'campaign_id' => $campaign->id,
                'agent'       => 'MicrosoftAdsCampaignOptimizationAgent',
                'action'      => 'microsoft_optimization_analysis',
                'details'     => $results,
                'status'      => 'completed',
            ]);
        }

        return $results;
    }

    /**
     * Detect overspend or underspend relative to the campaign daily budget.
     */
    private function checkBudgetPacing(Campaign $campaign, array &$results): void
    {
        $strategy = $campaign->strategies()->latest()->first();
        if (!$strategy || !$strategy->daily_budget) {
            return;
        }

        $data = MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->selectRaw('SUM(cost) as total_cost, COUNT(DISTINCT date) as days')
            ->first();

        if (!$data || ($data->days ?? 0) < 3) {
            return;
        }

        $avgDailySpend = $data->total_cost / $data->days;
        $budgetUtil    = $avgDailySpend / $strategy->daily_budget;

        if ($budgetUtil > 0.95) {
            // Spending at or above budget cap — may be limiting reach
            $results['issues'][] = [
                'type'             => 'budget_limited',
                'avg_daily_spend'  => round($avgDailySpend, 2),
                'daily_budget'     => $strategy->daily_budget,
                'utilisation_pct'  => round($budgetUtil * 100, 1),
            ];

            $results['actions'][] = [
                'type'        => 'increase_budget_recommended',
                'reason'      => 'Campaign spending at budget cap — potential missed impressions',
                'suggestion'  => 'Consider increasing daily budget by 20-30%',
            ];

            $this->notify($campaign, 'microsoft_budget_limited',
                'Microsoft Ads: Budget Capped',
                "Campaign #{$campaign->id} is spending at {$budgetUtil}% of its daily budget — reach may be limited."
            );
        } elseif ($budgetUtil < 0.30) {
            // Very low delivery — possible targeting, bid, or policy issue
            $results['issues'][] = [
                'type'            => 'low_delivery',
                'avg_daily_spend' => round($avgDailySpend, 2),
                'daily_budget'    => $strategy->daily_budget,
                'utilisation_pct' => round($budgetUtil * 100, 1),
            ];

            $results['actions'][] = [
                'type'       => 'investigate_delivery',
                'reason'     => 'Campaign spending well below budget — check bids, targeting, and ad approval status',
            ];
        }
    }

    /**
     * Detect week-over-week CTR decline, which signals creative fatigue or quality issues.
     */
    private function checkCtrDecline(Campaign $campaign, array &$results): void
    {
        $thisWeek = MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->first();

        $lastWeek = MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
            ->whereBetween('date', [
                now()->subDays(14)->toDateString(),
                now()->subDays(7)->toDateString(),
            ])
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->first();

        if (!$thisWeek || !$lastWeek) {
            return;
        }

        $thisImpressions = $thisWeek->impressions ?? 0;
        $lastImpressions = $lastWeek->impressions ?? 0;

        if ($thisImpressions < self::MIN_IMPRESSIONS_FOR_ANALYSIS || $lastImpressions < self::MIN_IMPRESSIONS_FOR_ANALYSIS) {
            return;
        }

        $thisCtr = $thisWeek->clicks > 0  ? $thisWeek->clicks  / $thisImpressions : 0;
        $lastCtr = $lastWeek->clicks > 0  ? $lastWeek->clicks  / $lastImpressions : 0;

        if ($lastCtr <= 0) {
            return;
        }

        $ctrDrop = ($lastCtr - $thisCtr) / $lastCtr;

        if ($ctrDrop >= self::CTR_DECLINE_THRESHOLD) {
            $dropPct = round($ctrDrop * 100, 1);

            $results['issues'][] = [
                'type'          => 'ctr_decline',
                'this_week_ctr' => round($thisCtr * 100, 3),
                'last_week_ctr' => round($lastCtr * 100, 3),
                'drop_pct'      => $dropPct,
            ];

            $this->createCreativeBrief($campaign, 'fatigue_refresh', [
                'reason'        => 'Microsoft Ads CTR declined week-over-week',
                'this_week_ctr' => round($thisCtr * 100, 3),
                'last_week_ctr' => round($lastCtr * 100, 3),
                'drop_pct'      => $dropPct,
            ]);

            $results['briefs'][] = 'fatigue_refresh';

            $this->notify($campaign, 'microsoft_ctr_decline',
                'Microsoft Ads: CTR Declining',
                "Campaign #{$campaign->id} CTR dropped {$dropPct}% WoW — creative refresh brief created."
            );
        }
    }

    /**
     * Compare actual CPL to Microsoft Ads benchmarks and flag if overspending.
     */
    private function checkCplVsBenchmark(Campaign $campaign, array &$results): void
    {
        $data = MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions')
            ->first();

        if (!$data || ($data->conversions ?? 0) < 5 || ($data->cost ?? 0) <= 0) {
            return;
        }

        $cpl = $data->cost / $data->conversions;

        // Infer objective from strategy bidding_strategy or fall back to 'conversions'
        $strategy  = $campaign->strategies()->latest()->first();
        $objective = $strategy?->bidding_strategy['objective'] ?? 'conversions';
        $benchmark = self::CPL_BENCHMARKS[$objective] ?? self::CPL_BENCHMARKS['conversions'];

        $threshold = $benchmark['high'] * self::CPL_OVERSPEND_MULTIPLIER;

        if ($cpl <= $threshold) {
            return;
        }

        $cplFmt = number_format($cpl, 2);

        $results['issues'][] = [
            'type'           => 'cpl_above_benchmark',
            'current_cpl'    => round($cpl, 2),
            'benchmark_high' => $benchmark['high'],
            'threshold'      => $threshold,
            'objective'      => $objective,
        ];

        $results['actions'][] = [
            'type'    => 'review_bidding_strategy',
            'reason'  => "CPL \${$cplFmt} exceeds 3x {$objective} benchmark — review bid strategy and landing page",
        ];

        $this->notify($campaign, 'microsoft_cpl_above_benchmark',
            'Microsoft Ads: CPL Above Benchmark',
            "Campaign #{$campaign->id} CPL \${$cplFmt} is 3x+ the {$objective} benchmark. Review bids and ad quality.",
            ['objective' => $objective, 'cpl' => round($cpl, 2)]
        );
    }

    /**
     * Warn if a campaign has very low impression count after 7+ days — possible targeting or bid issue.
     */
    private function checkImpressionShare(Campaign $campaign, array &$results): void
    {
        $data = MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->selectRaw('SUM(impressions) as impressions, COUNT(DISTINCT date) as days')
            ->first();

        $days        = $data->days        ?? 0;
        $impressions = $data->impressions ?? 0;

        if ($days < 5 || $impressions > self::MIN_IMPRESSIONS_FOR_ANALYSIS) {
            return;
        }

        $results['issues'][] = [
            'type'            => 'low_impression_volume',
            'total_impressions_7d' => $impressions,
            'days_with_data'  => $days,
        ];

        $results['actions'][] = [
            'type'   => 'check_ad_status_and_bids',
            'reason' => "Only {$impressions} impressions over {$days} days — verify ad approval, bid competitiveness, and keyword match types",
        ];

        Log::info("MicrosoftAdsCampaignOptimizationAgent: Low impression volume for campaign {$campaign->id}", [
            'impressions' => $impressions,
            'days'        => $days,
        ]);
    }

    private function createCreativeBrief(Campaign $campaign, string $briefType, array $context): void
    {
        $hasPending = CreativeBrief::where('campaign_id', $campaign->id)
            ->where('platform', 'microsoft')
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return;
        }

        CreativeBrief::create([
            'campaign_id'      => $campaign->id,
            'customer_id'      => $campaign->customer_id,
            'platform'         => 'microsoft',
            'brief_type'       => $briefType,
            'status'           => 'pending',
            'created_by_agent' => 'MicrosoftAdsCampaignOptimizationAgent',
            'ai_brief'         => "Microsoft Ads campaign requires creative refresh. Reason: {$context['reason']}.",
            'context'          => $context,
        ]);
    }

    private function notify(Campaign $campaign, string $type, string $title, string $message, array $extra = []): void
    {
        $cacheKey = "microsoft_opt_alert:{$type}:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addHours(24));

        $users = $campaign->customer->users ?? collect();
        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new CriticalAgentAlert(
            $type,
            $title,
            $message,
            array_merge(['campaign_id' => $campaign->id], $extra)
        ));
    }
}
