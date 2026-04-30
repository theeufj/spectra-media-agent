<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CrossChannelRebalanceLog;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\PlatformBudgetAllocation;
use App\Services\GeminiService;
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

        // Enrich with Gemini AI reasoning if enough data
        $aiReasoning = $this->getAiReasoning($snapshot, $allocation);
        if ($aiReasoning) {
            $recommendations['ai_reasoning'] = $aiReasoning;
            $allocation->update([
                'ai_reasoning' => $aiReasoning,
                'last_ai_analysis_at' => now(),
            ]);
        }

        return [
            'allocation' => $allocation->fresh(),
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
            'linkedin_ads_pct' => $allocation->linkedin_ads_pct,
        ];

        $suggested = $recommendations['suggested_splits'];
        $allocation->update([
            'google_ads_pct' => $suggested['google_ads_pct'],
            'facebook_ads_pct' => $suggested['facebook_ads_pct'],
            'microsoft_ads_pct' => $suggested['microsoft_ads_pct'],
            'linkedin_ads_pct' => $suggested['linkedin_ads_pct'] ?? 0,
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
            'linkedin_ads' => ['spend' => 0, 'conversions' => 0, 'conversion_value' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => 0],
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

            // LinkedIn Ads performance
            if ($campaign->linkedin_ads_campaign_id) {
                $liPerf = LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->where('date', '>=', $since)
                    ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value, SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->first();

                if ($liPerf) {
                    $snapshot['linkedin_ads']['spend'] += (float) $liPerf->cost;
                    $snapshot['linkedin_ads']['conversions'] += (float) $liPerf->conversions;
                    $snapshot['linkedin_ads']['conversion_value'] += (float) $liPerf->conversion_value;
                    $snapshot['linkedin_ads']['clicks'] += (int) $liPerf->clicks;
                    $snapshot['linkedin_ads']['impressions'] += (int) $liPerf->impressions;
                    $snapshot['linkedin_ads']['campaigns']++;
                }
            }
        }

        // Compute derived metrics
        foreach (['google_ads', 'facebook_ads', 'microsoft_ads', 'linkedin_ads'] as $platform) {
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
        $activePlatforms = collect(['google_ads', 'facebook_ads', 'microsoft_ads', 'linkedin_ads'])
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

        foreach (['google_ads', 'facebook_ads', 'microsoft_ads', 'linkedin_ads'] as $platform) {
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
        $sum = $suggested['google_ads_pct'] + $suggested['facebook_ads_pct'] + $suggested['microsoft_ads_pct'] + $suggested['linkedin_ads_pct'];
        if ($sum > 0 && abs($sum - 100) > 0.1) {
            foreach (['google_ads_pct', 'facebook_ads_pct', 'microsoft_ads_pct', 'linkedin_ads_pct'] as $field) {
                $suggested[$field] = round($suggested[$field] / $sum * 100, 1);
            }
        }

        // Estimate improvement
        $currentWeightedRoas = 0;
        $newWeightedRoas = 0;
        foreach (['google_ads', 'facebook_ads', 'microsoft_ads', 'linkedin_ads'] as $platform) {
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
        foreach (['google_ads', 'facebook_ads', 'microsoft_ads', 'linkedin_ads'] as $platform) {
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
        $splits = ['google_ads_pct' => 0, 'facebook_ads_pct' => 0, 'microsoft_ads_pct' => 0, 'linkedin_ads_pct' => 0];
        foreach ($activePlatforms as $platform) {
            $splits[str_replace('_ads', '_ads_pct', $platform)] = $pct;
        }
        return ['suggested_splits' => $splits, 'reasons' => ['Equal split across active platforms'], 'estimated_improvement_pct' => null];
    }

    protected function applyConstraints(array $splits, ?array $constraints): array
    {
        if (!$constraints) return $splits;

        foreach (['google_ads_pct', 'facebook_ads_pct', 'microsoft_ads_pct', 'linkedin_ads_pct'] as $field) {
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

    /**
     * Get Gemini AI reasoning for budget allocation decisions.
     */
    protected function getAiReasoning(array $snapshot, PlatformBudgetAllocation $allocation): ?array
    {
        try {
            $gemini = app(GeminiService::class);

            $prompt = "You are an expert digital advertising strategist. Analyze this cross-platform ad performance data and provide actionable budget allocation insights.\n\n";
            $prompt .= "Current Allocation:\n";
            $prompt .= "- Google Ads: {$allocation->google_ads_pct}%\n";
            $prompt .= "- Facebook/Instagram: {$allocation->facebook_ads_pct}%\n";
            $prompt .= "- Microsoft/Bing: {$allocation->microsoft_ads_pct}%\n";
            $prompt .= "- LinkedIn: {$allocation->linkedin_ads_pct}%\n";
            $prompt .= "- Monthly Budget: \${$allocation->total_monthly_budget}\n";
            $prompt .= "- Strategy: {$allocation->strategy}\n\n";
            $prompt .= "30-Day Performance by Platform:\n";

            foreach (['google_ads' => 'Google Ads', 'facebook_ads' => 'Facebook/Instagram', 'microsoft_ads' => 'Microsoft/Bing', 'linkedin_ads' => 'LinkedIn'] as $key => $name) {
                $p = $snapshot[$key];
                if ($p['campaigns'] > 0) {
                    $prompt .= "- {$name}: Spend \${$p['spend']}, ROAS {$p['roas']}x, CPA \${$p['cpa']}, Conversions {$p['conversions']}, CTR " . ($p['ctr'] * 100) . "%, {$p['campaigns']} campaigns\n";
                } else {
                    $prompt .= "- {$name}: No active campaigns\n";
                }
            }

            $prompt .= "\nRespond in JSON with exactly these keys:\n";
            $prompt .= "- \"summary\": 2-3 sentence executive summary\n";
            $prompt .= "- \"insights\": array of 3-5 specific insights about performance\n";
            $prompt .= "- \"action_items\": array of 2-3 specific recommended actions\n";
            $prompt .= "- \"risk_flags\": array of any concerning metrics (empty array if none)\n";

            $response = $gemini->generateContent(config('ai.models.default'), $prompt);
            $responseText = $response['text'] ?? null;
            $json = $responseText ? json_decode($responseText, true) : null;

            if ($json && isset($json['summary'])) {
                return $json;
            }

            // Try to extract JSON from response
            if ($responseText && preg_match('/\{[\s\S]*\}/', $responseText, $matches)) {
                $json = json_decode($matches[0], true);
                if ($json && isset($json['summary'])) {
                    return $json;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('CrossChannelBudgetAllocator: AI reasoning failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
