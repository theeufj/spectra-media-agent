<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CrossChannelRebalanceLog;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\PlatformBudgetAllocation;
use Illuminate\Support\Facades\Log;

class CrossChannelBudgetAllocator
{
    /**
     * Analyze cross-channel performance and recommend/execute budget reallocation.
     */
    public function analyze(Customer $customer): array
    {
        $allocation = PlatformBudgetAllocation::firstOrCreate(
            ['customer_id' => $customer->id],
            [
                'total_monthly_budget' => $customer->campaigns()->sum('total_budget') ?: 1000,
                'google_ads_pct' => 100,
                'facebook_ads_pct' => 0,
                'microsoft_ads_pct' => 0,
                'strategy' => 'performance',
            ]
        );

        $snapshot = $this->getPerformanceSnapshot($customer);
        $recommendations = $this->generateRecommendations($allocation, $snapshot);

        return [
            'allocation' => $allocation,
            'snapshot' => $snapshot,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Execute a rebalance based on recommendations.
     */
    public function rebalance(Customer $customer, string $trigger = 'manual'): array
    {
        $allocation = PlatformBudgetAllocation::where('customer_id', $customer->id)->first();
        if (!$allocation) {
            return ['error' => 'No budget allocation configured'];
        }

        $snapshot = $this->getPerformanceSnapshot($customer);
        $recommendations = $this->generateRecommendations($allocation, $snapshot);

        if (empty($recommendations['suggested_splits'])) {
            return ['status' => 'no_changes', 'reason' => 'Insufficient data for rebalance'];
        }

        $beforeAllocation = [
            'google_ads_pct' => $allocation->google_ads_pct,
            'facebook_ads_pct' => $allocation->facebook_ads_pct,
            'microsoft_ads_pct' => $allocation->microsoft_ads_pct,
        ];

        $suggested = $recommendations['suggested_splits'];
        $allocation->update([
            'google_ads_pct' => $suggested['google_ads_pct'],
            'facebook_ads_pct' => $suggested['facebook_ads_pct'],
            'microsoft_ads_pct' => $suggested['microsoft_ads_pct'],
            'last_rebalanced_at' => now(),
        ]);

        $log = CrossChannelRebalanceLog::create([
            'customer_id' => $customer->id,
            'before_allocation' => $beforeAllocation,
            'after_allocation' => $suggested,
            'performance_snapshot' => $snapshot,
            'recommendations' => $recommendations,
            'trigger' => $trigger,
            'auto_executed' => $trigger !== 'manual',
            'estimated_improvement_pct' => $recommendations['estimated_improvement_pct'] ?? null,
        ]);

        Log::info('CrossChannelBudgetAllocator: Rebalanced', [
            'customer_id' => $customer->id,
            'before' => $beforeAllocation,
            'after' => $suggested,
        ]);

        return [
            'status' => 'rebalanced',
            'log' => $log,
            'before' => $beforeAllocation,
            'after' => $suggested,
        ];
    }

    /**
     * Get performance snapshot across all platforms for the last 30 days.
     */
    protected function getPerformanceSnapshot(Customer $customer): array
    {
        $campaigns = $customer->campaigns;
        $since = now()->subDays(30);
        $snapshot = [
            'google_ads' => ['spend' => 0, 'conversions' => 0, 'conversion_value' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => 0],
            'facebook_ads' => ['spend' => 0, 'conversions' => 0, 'conversion_value' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => 0],
            'microsoft_ads' => ['spend' => 0, 'conversions' => 0, 'conversion_value' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => 0],
            'period_days' => 30,
        ];

        foreach ($campaigns as $campaign) {
            // Google Ads performance
            if ($campaign->google_ads_campaign_id) {
                $googlePerf = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->where('date', '>=', $since)
                    ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value, SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->first();

                if ($googlePerf) {
                    $snapshot['google_ads']['spend'] += (float) $googlePerf->cost;
                    $snapshot['google_ads']['conversions'] += (float) $googlePerf->conversions;
                    $snapshot['google_ads']['conversion_value'] += (float) $googlePerf->conversion_value;
                    $snapshot['google_ads']['clicks'] += (int) $googlePerf->clicks;
                    $snapshot['google_ads']['impressions'] += (int) $googlePerf->impressions;
                    $snapshot['google_ads']['campaigns']++;
                }
            }

            // Facebook Ads performance
            if ($campaign->facebook_ads_campaign_id) {
                $fbPerf = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->where('date', '>=', $since)
                    ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions, SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->first();

                if ($fbPerf) {
                    $snapshot['facebook_ads']['spend'] += (float) $fbPerf->cost;
                    $snapshot['facebook_ads']['conversions'] += (float) $fbPerf->conversions;
                    $snapshot['facebook_ads']['clicks'] += (int) $fbPerf->clicks;
                    $snapshot['facebook_ads']['impressions'] += (int) $fbPerf->impressions;
                    $snapshot['facebook_ads']['campaigns']++;
                }
            }

            // Microsoft Ads performance
            if ($campaign->microsoft_ads_campaign_id) {
                $msPerf = MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->where('date', '>=', $since)
                    ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value, SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->first();

                if ($msPerf) {
                    $snapshot['microsoft_ads']['spend'] += (float) $msPerf->cost;
                    $snapshot['microsoft_ads']['conversions'] += (float) $msPerf->conversions;
                    $snapshot['microsoft_ads']['conversion_value'] += (float) $msPerf->conversion_value;
                    $snapshot['microsoft_ads']['clicks'] += (int) $msPerf->clicks;
                    $snapshot['microsoft_ads']['impressions'] += (int) $msPerf->impressions;
                    $snapshot['microsoft_ads']['campaigns']++;
                }
            }
        }

        // Compute derived metrics
        foreach (['google_ads', 'facebook_ads', 'microsoft_ads'] as $platform) {
            $p = &$snapshot[$platform];
            $p['roas'] = $p['spend'] > 0 ? round($p['conversion_value'] / $p['spend'], 2) : 0;
            $p['cpa'] = $p['conversions'] > 0 ? round($p['spend'] / $p['conversions'], 2) : 0;
            $p['ctr'] = $p['impressions'] > 0 ? round($p['clicks'] / $p['impressions'], 4) : 0;
        }

        return $snapshot;
    }

    /**
     * Generate rebalance recommendations based on strategy.
     */
    protected function generateRecommendations(PlatformBudgetAllocation $allocation, array $snapshot): array
    {
        $activePlatforms = collect(['google_ads', 'facebook_ads', 'microsoft_ads'])
            ->filter(fn ($p) => $snapshot[$p]['campaigns'] > 0);

        if ($activePlatforms->count() < 2) {
            return ['status' => 'single_platform', 'message' => 'Need at least 2 active platforms for cross-channel optimization'];
        }

        return match ($allocation->strategy) {
            'performance' => $this->performanceBasedSplits($allocation, $snapshot, $activePlatforms),
            'roas_target' => $this->roasTargetSplits($allocation, $snapshot, $activePlatforms),
            'equal' => $this->equalSplits($activePlatforms),
            default => ['status' => 'manual', 'message' => 'Manual allocation — no auto recommendations'],
        };
    }

    protected function performanceBasedSplits(PlatformBudgetAllocation $allocation, array $snapshot, $activePlatforms): array
    {
        // Weight platforms by efficiency score = (ROAS * 0.6) + (1/CPA * 0.4 * 100)
        $scores = [];
        foreach ($activePlatforms as $platform) {
            $p = $snapshot[$platform];
            $roasScore = $p['roas'] * 0.6;
            $cpaScore = $p['cpa'] > 0 ? (1 / $p['cpa']) * 40 : 0;
            $scores[$platform] = max($roasScore + $cpaScore, 0.01);
        }

        $total = array_sum($scores);
        $suggested = [];
        $reasons = [];

        foreach (['google_ads', 'facebook_ads', 'microsoft_ads'] as $platform) {
            $pctField = str_replace('_ads', '_ads_pct', $platform);
            if (isset($scores[$platform])) {
                $rawPct = ($scores[$platform] / $total) * 100;
                // Smooth toward current allocation (70% new, 30% current) to avoid drastic swings
                $currentPct = (float) $allocation->$pctField;
                $suggested[$pctField] = round($rawPct * 0.7 + $currentPct * 0.3, 1);
                $change = $suggested[$pctField] - $currentPct;
                if (abs($change) >= 1) {
                    $direction = $change > 0 ? 'Increase' : 'Decrease';
                    $reasons[] = "{$direction} " . str_replace('_', ' ', $platform) . " by " . abs(round($change, 1)) . "% based on efficiency score";
                }
            } else {
                $suggested[$pctField] = 0;
            }
        }

        // Apply constraints
        $suggested = $this->applyConstraints($suggested, $allocation->constraints);

        // Normalize to 100%
        $sum = $suggested['google_ads_pct'] + $suggested['facebook_ads_pct'] + $suggested['microsoft_ads_pct'];
        if ($sum > 0 && abs($sum - 100) > 0.1) {
            foreach (['google_ads_pct', 'facebook_ads_pct', 'microsoft_ads_pct'] as $field) {
                $suggested[$field] = round($suggested[$field] / $sum * 100, 1);
            }
        }

        // Estimate improvement
        $currentWeightedRoas = 0;
        $newWeightedRoas = 0;
        foreach (['google_ads', 'facebook_ads', 'microsoft_ads'] as $platform) {
            $pctField = str_replace('_ads', '_ads_pct', $platform);
            $roas = $snapshot[$platform]['roas'];
            $currentWeightedRoas += $roas * ((float) $allocation->$pctField / 100);
            $newWeightedRoas += $roas * ($suggested[$pctField] / 100);
        }
        $improvement = $currentWeightedRoas > 0 ? round(($newWeightedRoas - $currentWeightedRoas) / $currentWeightedRoas * 100, 1) : 0;

        return [
            'suggested_splits' => $suggested,
            'reasons' => $reasons,
            'scores' => $scores,
            'estimated_improvement_pct' => $improvement,
        ];
    }

    protected function roasTargetSplits(PlatformBudgetAllocation $allocation, array $snapshot, $activePlatforms): array
    {
        $target = (float) $allocation->target_roas;
        $suggested = [];

        // Platforms meeting or exceeding target ROAS get more budget
        $above = [];
        $below = [];
        foreach ($activePlatforms as $platform) {
            if ($snapshot[$platform]['roas'] >= $target) {
                $above[$platform] = $snapshot[$platform]['roas'];
            } else {
                $below[$platform] = $snapshot[$platform]['roas'];
            }
        }

        $totalAbove = array_sum($above);
        foreach (['google_ads', 'facebook_ads', 'microsoft_ads'] as $platform) {
            $pctField = str_replace('_ads', '_ads_pct', $platform);
            if (isset($above[$platform])) {
                $suggested[$pctField] = $totalAbove > 0 ? round(($above[$platform] / $totalAbove) * 85, 1) : 0;
            } elseif (isset($below[$platform])) {
                $suggested[$pctField] = round(15 / max(count($below), 1), 1);
            } else {
                $suggested[$pctField] = 0;
            }
        }

        $suggested = $this->applyConstraints($suggested, $allocation->constraints);
        $sum = array_sum($suggested);
        if ($sum > 0 && abs($sum - 100) > 0.1) {
            foreach ($suggested as &$v) { $v = round($v / $sum * 100, 1); }
        }

        return ['suggested_splits' => $suggested, 'reasons' => ["ROAS target: {$target}x"], 'estimated_improvement_pct' => null];
    }

    protected function equalSplits($activePlatforms): array
    {
        $pct = round(100 / $activePlatforms->count(), 1);
        $splits = ['google_ads_pct' => 0, 'facebook_ads_pct' => 0, 'microsoft_ads_pct' => 0];
        foreach ($activePlatforms as $platform) {
            $splits[str_replace('_ads', '_ads_pct', $platform)] = $pct;
        }
        return ['suggested_splits' => $splits, 'reasons' => ['Equal split across active platforms'], 'estimated_improvement_pct' => null];
    }

    protected function applyConstraints(array $splits, ?array $constraints): array
    {
        if (!$constraints) return $splits;

        foreach (['google_ads_pct', 'facebook_ads_pct', 'microsoft_ads_pct'] as $field) {
            $platform = str_replace('_pct', '', $field);
            if (isset($constraints[$platform]['min'])) {
                $splits[$field] = max($splits[$field], $constraints[$platform]['min']);
            }
            if (isset($constraints[$platform]['max'])) {
                $splits[$field] = min($splits[$field], $constraints[$platform]['max']);
            }
        }

        return $splits;
    }
}
