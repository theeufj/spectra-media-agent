<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\AgentActivity;
use App\Models\CreativeBrief;
use App\Models\LinkedInAdsPerformanceData;
use App\Notifications\CriticalAgentAlert;
use App\Services\LinkedInAds\CampaignService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class LinkedInCampaignOptimizationAgent
{
    // LinkedIn benchmark CPL ranges by objective (USD)
    private const CPL_BENCHMARKS = [
        'LEAD_GEN'         => ['low' => 50,  'high' => 200],
        'WEBSITE_VISITS'   => ['low' => 5,   'high' => 30],
        'BRAND_AWARENESS'  => ['low' => 2,   'high' => 15],
        'ENGAGEMENT'       => ['low' => 3,   'high' => 20],
        'VIDEO_VIEWS'      => ['low' => 0.10, 'high' => 0.50],
    ];

    private const FREQUENCY_FATIGUE_THRESHOLD = 4.0;  // impressions/reach per 30 days
    private const OPEN_RATE_DROP_THRESHOLD     = 0.30; // 30% WoW decline triggers pause
    private const CPL_OVERSPEND_MULTIPLIER     = 3.0;  // CPL >3x benchmark triggers objective review

    public function analyze(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'actions'     => [],
            'issues'      => [],
            'briefs'      => [],
        ];

        if (!$campaign->linkedin_campaign_id) {
            return $results;
        }

        $this->checkFrequencyFatigue($campaign, $results);
        $this->checkMessageAdOpenRate($campaign, $results);
        $this->checkCplVsBenchmark($campaign, $results);

        if (!empty($results['actions']) || !empty($results['issues'])) {
            AgentActivity::create([
                'campaign_id' => $campaign->id,
                'agent'       => 'LinkedInCampaignOptimizationAgent',
                'action'      => 'linkedin_optimization_analysis',
                'details'     => $results,
                'status'      => 'completed',
            ]);
        }

        return $results;
    }

    private function checkFrequencyFatigue(Campaign $campaign, array &$results): void
    {
        $data = LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')
            ->first();

        if (!$data || ($data->impressions ?? 0) < 500) {
            return;
        }

        // LinkedIn doesn't expose reach directly; use clicks as a proxy for unique reach estimate.
        // A very low CTR combined with high impressions signals frequency fatigue.
        $ctr = $data->clicks > 0 ? ($data->clicks / $data->impressions) * 100 : 0;
        $estimatedReach = max(1, $data->clicks * 10); // rough: assume 1-in-10 unique users click
        $estimatedFrequency = $data->impressions / $estimatedReach;

        if ($estimatedFrequency >= self::FREQUENCY_FATIGUE_THRESHOLD) {
            $results['issues'][] = [
                'type'                => 'audience_frequency_fatigue',
                'estimated_frequency' => round($estimatedFrequency, 1),
                'impressions_30d'     => $data->impressions,
                'ctr'                 => round($ctr, 3),
            ];

            $this->createCreativeBrief($campaign, 'fatigue_refresh', [
                'reason'              => 'LinkedIn audience frequency fatigue detected',
                'estimated_frequency' => round($estimatedFrequency, 1),
                'ctr_30d'             => round($ctr, 3),
                'spend_30d'           => round($data->cost ?? 0, 2),
            ]);

            $results['briefs'][] = 'fatigue_refresh';

            $this->notify($campaign, 'linkedin_frequency_fatigue',
                'LinkedIn Audience Fatigue',
                "Campaign #{$campaign->id} estimated frequency {$estimatedFrequency}x in 30 days — audience is saturated. Creative refresh brief created."
            );
        }
    }

    private function checkMessageAdOpenRate(Campaign $campaign, array &$results): void
    {
        // Message Ads (InMail) — detect open rate decline WoW using clicks as proxy for opens
        $thisWeek = LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost')
            ->first();

        $lastWeek = LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
            ->whereBetween('date', [now()->subDays(14)->toDateString(), now()->subDays(7)->toDateString()])
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->first();

        if (!$thisWeek || !$lastWeek) {
            return;
        }

        $thisImpressions = $thisWeek->impressions ?? 0;
        $lastImpressions = $lastWeek->impressions ?? 0;

        if ($thisImpressions < 200 || $lastImpressions < 200) {
            return;
        }

        $thisCtr  = $thisWeek->clicks  > 0 ? $thisWeek->clicks  / $thisImpressions : 0;
        $lastCtr  = $lastWeek->clicks  > 0 ? $lastWeek->clicks  / $lastImpressions : 0;

        if ($lastCtr <= 0) {
            return;
        }

        $openRateDrop = ($lastCtr - $thisCtr) / $lastCtr;

        if ($openRateDrop >= self::OPEN_RATE_DROP_THRESHOLD) {
            $dropPct = round($openRateDrop * 100, 1);

            try {
                $service = new CampaignService($campaign->customer);
                $service->updateStatus($campaign->linkedin_campaign_id, 'PAUSED');
                $results['actions'][] = ['type' => 'campaign_paused', 'reason' => 'message_ad_open_rate_drop'];
            } catch (\Exception $e) {
                Log::warning("LinkedInCampaignOptimizationAgent: pause failed for campaign {$campaign->id}: " . $e->getMessage());
            }

            $this->createCreativeBrief($campaign, 'fatigue_refresh', [
                'reason'       => 'LinkedIn Message Ad CTR/open rate dropped WoW',
                'this_week_ctr' => round($thisCtr * 100, 3),
                'last_week_ctr' => round($lastCtr * 100, 3),
                'drop_pct'     => $dropPct,
            ]);

            $results['issues'][] = [
                'type'          => 'message_ad_open_rate_drop',
                'drop_pct'      => $dropPct,
                'this_week_ctr' => round($thisCtr * 100, 3),
                'last_week_ctr' => round($lastCtr * 100, 3),
            ];

            $this->notify($campaign, 'linkedin_open_rate_drop',
                'LinkedIn Message Ad Decline',
                "Campaign #{$campaign->id} CTR dropped {$dropPct}% WoW — campaign paused, creative brief queued."
            );
        }
    }

    private function checkCplVsBenchmark(Campaign $campaign, array &$results): void
    {
        $data = LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions')
            ->first();

        if (!$data || ($data->conversions ?? 0) < 5 || ($data->cost ?? 0) <= 0) {
            return;
        }

        $cpl = $data->cost / $data->conversions;

        // Try to detect the campaign objective from campaign settings
        $objective = $campaign->strategy->objective_type ?? 'LEAD_GEN';
        $benchmark = self::CPL_BENCHMARKS[$objective] ?? self::CPL_BENCHMARKS['LEAD_GEN'];

        if ($cpl <= ($benchmark['high'] * self::CPL_OVERSPEND_MULTIPLIER)) {
            return;
        }

        $benchmarkHigh = $benchmark['high'];
        $threshold     = $benchmarkHigh * self::CPL_OVERSPEND_MULTIPLIER;
        $currentCplFmt = number_format($cpl, 2);

        $suggestedObjective = $this->suggestObjectiveSwitch($objective);

        $results['issues'][] = [
            'type'               => 'cpl_above_benchmark',
            'current_cpl'        => round($cpl, 2),
            'benchmark_high'     => $benchmarkHigh,
            'threshold'          => $threshold,
            'current_objective'  => $objective,
            'suggested_objective' => $suggestedObjective,
        ];

        $multiplier = self::CPL_OVERSPEND_MULTIPLIER;
        Log::info("LinkedInCampaignOptimizationAgent: Campaign {$campaign->id} CPL \${$currentCplFmt} is >{$multiplier}x benchmark ({$objective}). Suggesting switch to {$suggestedObjective}.");

        if ($suggestedObjective) {
            $results['actions'][] = [
                'type'               => 'objective_switch_recommended',
                'from'               => $objective,
                'to'                 => $suggestedObjective,
                'reason'             => "CPL \${$currentCplFmt} exceeds 3x benchmark",
            ];

            $this->notify($campaign, 'linkedin_cpl_above_benchmark',
                'LinkedIn CPL Above Benchmark',
                "Campaign #{$campaign->id} CPL \${$currentCplFmt} is 3x+ the {$objective} benchmark. Consider switching objective to {$suggestedObjective}.",
                ['suggested_objective' => $suggestedObjective]
            );
        }
    }

    private function suggestObjectiveSwitch(string $current): ?string
    {
        return match ($current) {
            'BRAND_AWARENESS' => 'LEAD_GEN',
            'ENGAGEMENT'      => 'WEBSITE_VISITS',
            'WEBSITE_VISITS'  => 'LEAD_GEN',
            default           => null,
        };
    }

    private function createCreativeBrief(Campaign $campaign, string $briefType, array $context): void
    {
        $hasPending = CreativeBrief::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return;
        }

        CreativeBrief::create([
            'campaign_id'      => $campaign->id,
            'customer_id'      => $campaign->customer_id,
            'platform'         => 'linkedin',
            'brief_type'       => $briefType,
            'status'           => 'pending',
            'created_by_agent' => 'LinkedInCampaignOptimizationAgent',
            'ai_brief'         => "LinkedIn campaign requires creative refresh. Reason: {$context['reason']}. Review performance data and produce fresh ad creatives.",
            'context'          => $context,
        ]);
    }

    private function notify(Campaign $campaign, string $type, string $title, string $message, array $extra = []): void
    {
        $cacheKey = "linkedin_opt_alert:{$type}:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addHours(24));

        $admins = $campaign->customer->users ?? collect();
        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new CriticalAgentAlert(
            $type,
            $title,
            $message,
            array_merge(['campaign_id' => $campaign->id], $extra)
        ));
    }
}
