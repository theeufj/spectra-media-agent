<?php

namespace App\Services;

use App\Models\CrossChannelRebalanceLog;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\PlatformBudgetAllocation;
use App\Services\Campaigns\BudgetAllocationEvidence;
use Illuminate\Support\Facades\DB;
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

        if (! empty($recommendations['suggested_splits'])) {
            $recommendations['approval_fingerprint'] = $this->fingerprint($allocation, $recommendations['suggested_splits'], $snapshot['evidence']);
        }

        // Enrich with Gemini AI reasoning if enough data
        $aiReasoning = empty($recommendations['suggested_splits']) ? null : $this->getAiReasoning($snapshot, $allocation);
        if ($aiReasoning) {
            $recommendations['ai_reasoning'] = $aiReasoning;
            $allocation->update([
                'ai_reasoning' => $aiReasoning,
                'last_ai_analysis_at' => now(),
            ]);
        }

        // Unqualified historical aggregates are available for diagnostics, never as proposal evidence in the customer's budget editor.
        if (empty($snapshot['evidence'])) {
            foreach (['google_ads', 'facebook_ads', 'microsoft_ads', 'linkedin_ads'] as $platform) {
                $snapshot[$platform] = ['campaigns' => $snapshot[$platform]['campaigns']];
            }
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
    public function rebalance(Customer $customer, string $trigger = 'manual', ?string $approvedFingerprint = null): array
    {
        $allocation = PlatformBudgetAllocation::where('customer_id', $customer->id)->first();
        if (! $allocation) {
            return ['status' => 'no_changes', 'reason' => 'No budget allocation configured'];
        }
        $snapshot = $this->getPerformanceSnapshot($customer);

        return DB::transaction(function () use ($customer, $trigger, $approvedFingerprint, $snapshot) {
            $allocation = PlatformBudgetAllocation::where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
            $recommendations = $this->generateRecommendations($allocation, $snapshot);
            $suggested = $recommendations['suggested_splits'] ?? [];
            if (! $suggested) {
                return ['status' => 'no_changes', 'reason' => 'No new split is available.'];
            }
            $fields = ['google_ads_pct', 'facebook_ads_pct', 'microsoft_ads_pct', 'linkedin_ads_pct'];
            $before = $allocation->only($fields);
            $suggested = array_replace(array_fill_keys($fields, 0), array_intersect_key($suggested, $before));
            if (array_any($suggested, fn ($pct) => ! is_numeric($pct) || $pct < 0 || $pct > 100) || abs(array_sum($suggested) - 100) > 0.5) {
                return ['status' => 'no_changes', 'reason' => 'The proposed percentages do not fit the budget envelope.'];
            }
            $fingerprint = $this->fingerprint($allocation, $suggested, $snapshot['evidence']);
            $recommendations['approval_fingerprint'] = $fingerprint;
            if ($trigger === 'manual' && (! $approvedFingerprint || ! hash_equals($fingerprint, $approvedFingerprint))) {
                return ['status' => 'needs_approval', 'reason' => 'The proposed split changed. Review the current percentages before saving.'];
            }
            $approved = $trigger === 'manual';
            if ($approved) {
                $allocation->update($suggested + ['last_rebalanced_at' => now()]);
            }
            $log = CrossChannelRebalanceLog::create(['customer_id' => $customer->id, 'before_allocation' => $before,
                'after_allocation' => $approved ? $suggested : $before, 'performance_snapshot' => $snapshot,
                'recommendations' => $recommendations, 'trigger' => $trigger, 'auto_executed' => false,
                'estimated_improvement_pct' => $recommendations['estimated_improvement_pct'] ?? null]);

            return ['status' => $approved ? 'rebalanced' : 'proposed', 'log' => $log, 'before' => $before,
                'after' => $approved ? $suggested : $before, 'suggested' => $suggested];
        });
    }

    private function fingerprint(PlatformBudgetAllocation $allocation, array $suggested, string $evidence): string
    {
        $keys = ['google_ads_pct', 'facebook_ads_pct', 'microsoft_ads_pct', 'linkedin_ads_pct'];
        $values = fn (array $splits) => array_map(fn ($key) => (int) round((float) ($splits[$key] ?? 0) * 100), $keys);

        return hash('sha256', json_encode([$allocation->customer_id, (string) $allocation->total_monthly_budget,
            $allocation->strategy, $evidence, $values($allocation->only($keys)), $values($suggested)], JSON_THROW_ON_ERROR));
    }

    /**
     * Get performance snapshot across all platforms for the last 30 days.
     */
    protected function getPerformanceSnapshot(Customer $customer): array
    {
        $campaigns = $customer->campaigns()->with(['customer', 'strategies'])->orderBy('id')->get();
        $since = now()->subDays(30);
        $snapshot = [
            'google_ads' => ['spend' => 0, 'conversions' => 0, 'conversion_value' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => 0],
            'facebook_ads' => ['spend' => 0, 'conversions' => 0, 'conversion_value' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => 0],
            'microsoft_ads' => ['spend' => 0, 'conversions' => 0, 'conversion_value' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => 0],
            'linkedin_ads' => ['spend' => 0, 'conversions' => 0, 'conversion_value' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => 0],
            'period_days' => 30,
        ];

        // The column that says "this campaign runs here". LinkedIn's is
        // `linkedin_campaign_id` — every other platform uses
        // `{platform}_ads_campaign_id`, and this one does not. Reading the
        // consistent-looking name returned null for every campaign, so
        // LinkedIn spend was silently dropped from every rebalance snapshot
        // and its budget arm was rebalanced against zero performance.
        $platforms = [
            'google_ads' => ['column' => 'google_ads_campaign_id', 'model' => GoogleAdsPerformanceData::class],
            'facebook_ads' => ['column' => 'facebook_ads_campaign_id', 'model' => FacebookAdsPerformanceData::class],
            'microsoft_ads' => ['column' => 'microsoft_ads_campaign_id', 'model' => MicrosoftAdsPerformanceData::class],
            'linkedin_ads' => ['column' => 'linkedin_campaign_id', 'model' => LinkedInAdsPerformanceData::class],
        ];

        $resources = app(BudgetAllocationEvidence::class)->resources($customer, $campaigns, 30);
        $snapshot['evidence'] = null;
        if ($resources !== []) {
            $basis = collect($resources)->map(fn ($resource) => collect($resource)->except('campaign')->all())->sortBy(fn ($r) => $r['platform'].'/'.$r['resource_id'])->values();
            $snapshot['evidence'] = hash('sha256', $basis->toJson(JSON_THROW_ON_ERROR));
            $snapshot['goal'] = $resources[0]['goal'];
            foreach (collect($resources)->groupBy('platform') as $name => $group) {
                $key = $name.'_ads';
                $snapshot[$key]['campaigns'] = $group->count();
                foreach (['spend', 'conversions', 'conversion_value', 'clicks', 'impressions'] as $metric) {
                    $snapshot[$key][$metric] = $group->sum($metric);
                }
            }
        }

        // One aggregate per platform over that platform's campaigns, rather
        // than one per campaign per platform. SUM() is additive, so the totals
        // are unchanged, but a forty-campaign account no longer issues 160
        // queries to build one snapshot.
        //
        // Writing the four arms once also restores Facebook's
        // conversion_value: its copy of this block selected and accumulated
        // every column except that one, so facebook_ads.roas was structurally
        // 0 and the performance strategy scored the platform on CPA alone.
        foreach ($resources === [] ? $platforms : [] as $key => $platform) {
            $campaignIds = $campaigns
                ->filter(fn ($campaign) => (bool) $campaign->{$platform['column']})
                ->pluck('id');

            if ($campaignIds->isEmpty()) {
                continue;
            }

            $snapshot[$key]['campaigns'] = $campaignIds->count();

            $perf = $platform['model']::whereIn('campaign_id', $campaignIds)
                ->where('date', '>=', $since)
                ->selectRaw('SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value, SUM(clicks) as clicks, SUM(impressions) as impressions')
                ->first();

            if ($perf) {
                $snapshot[$key]['spend'] = (float) $perf->cost;
                $snapshot[$key]['conversions'] = (float) $perf->conversions;
                $snapshot[$key]['conversion_value'] = (float) $perf->conversion_value;
                $snapshot[$key]['clicks'] = (int) $perf->clicks;
                $snapshot[$key]['impressions'] = (int) $perf->impressions;
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
        if (empty($snapshot['evidence']) || ($allocation->strategy === 'roas_target' && ($snapshot['goal'] ?? null) !== 'purchase')) {
            return ['status' => 'insufficient_evidence', 'message' => 'A split needs comparable verified outcomes and account spend in the same billing currency on both channels.'];
        }
        foreach (['google_ads', 'facebook_ads', 'microsoft_ads', 'linkedin_ads'] as $platform) {
            if ($allocation->{$platform.'_pct'} > 0 && $snapshot[$platform]['campaigns'] < 1) {
                return ['status' => 'insufficient_evidence', 'message' => 'An allocated channel has no comparable reporting data.'];
            }
        }
        $activePlatforms = collect(['google_ads', 'facebook_ads', 'microsoft_ads', 'linkedin_ads'])
            ->filter(fn ($p) => $snapshot[$p]['campaigns'] > 0);

        if ($activePlatforms->count() < 2) {
            return ['status' => 'single_platform', 'message' => 'Need at least 2 active platforms for cross-channel optimization'];
        }

        if (in_array($allocation->strategy, ['performance', 'roas_target'], true) && $activePlatforms->contains(
            fn ($p) => $snapshot[$p]['spend'] <= 0 || $snapshot[$p]['conversions'] < config('optimization.budget_intelligence.reallocation_min_conversions', 15)
        )) {
            return ['status' => 'insufficient_evidence', 'message' => 'More verified conversions are needed on each channel before comparing returns.'];
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
                    $reasons[] = "{$direction} ".str_replace('_', ' ', $platform).' by '.abs(round($change, 1)).'% based on efficiency score';
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
            foreach ($suggested as &$v) {
                $v = round($v / $sum * 100, 1);
            }
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
        if (! $constraints) {
            return $splits;
        }

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
                    $prompt .= "- {$name}: Spend \${$p['spend']}, ROAS {$p['roas']}x, CPA \${$p['cpa']}, Conversions {$p['conversions']}, CTR ".($p['ctr'] * 100)."%, {$p['campaigns']} campaigns\n";
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
        } catch (\Throwable $e) {
            report($e);
            Log::warning('CrossChannelBudgetAllocator: AI reasoning failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
