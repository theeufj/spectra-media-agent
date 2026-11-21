<?php

namespace App\Services\Agents;

use App\Models\ABTest;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\CreateCampaignExperiment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ABTestingAgent
 *
 * Manages automated A/B testing for ad creative assets. Tracks variant performance,
 * runs chi-squared significance testing at 95% confidence, and auto-applies winners.
 */
class ABTestingAgent
{
    protected GeminiService $gemini;

    // Minimum sample sizes before evaluating significance
    protected int $minImpressionsPerVariant = 500;

    protected int $minTotalClicks = 50;

    // Chi-squared critical value for 95% confidence with 1 degree of freedom
    protected float $chiSquaredCritical95 = 3.841;

    // Maximum test duration before auto-stopping (days)
    protected int $maxTestDurationDays = 30;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Create a new A/B test for a strategy's creative assets.
     */
    public function createTest(Strategy $strategy, string $testType, array $variants): ABTest
    {
        $formattedVariants = array_map(function ($variant, $index) {
            return [
                'id' => $variant['id'] ?? Str::uuid()->toString(),
                'label' => $variant['label'] ?? 'Variant '.chr(65 + $index), // A, B, C...
                'content' => $variant['content'],
                'treatment_id' => $variant['treatment_id'] ?? null,
                'impressions' => 0,
                'clicks' => 0,
                'conversions' => 0,
                'cost' => 0,
            ];
        }, $variants, array_keys($variants));

        return ABTest::create([
            'strategy_id' => $strategy->id,
            'campaign_id' => $strategy->campaign_id,
            'test_type' => $testType,
            'status' => ABTest::STATUS_RUNNING,
            'variants' => $formattedVariants,
            'results' => ['binding' => app(\App\Services\Conversions\CampaignOutcomeEvidence::class)->binding($strategy)['fingerprint']],
            'started_at' => now(),
        ]);
    }

    /**
     * Evaluate a running A/B test for statistical significance.
     */
    public function evaluateTest(ABTest $test): array
    {
        if (! $test->isRunning()) {
            return ['action' => 'skip', 'reason' => 'Test is not running'];
        }

        // Check max duration
        if ($test->started_at->diffInDays(now()) > $this->maxTestDurationDays) {
            $test->markStopped();

            return ['action' => 'stopped', 'reason' => 'Maximum test duration exceeded'];
        }

        if (! $test->strategy) {
            return ['action' => 'skip', 'reason' => 'The original strategy is unavailable.'];
        }
        $binding = app(\App\Services\Conversions\CampaignOutcomeEvidence::class)->binding($test->strategy);
        if (($test->results['binding'] ?? null) !== $binding['fingerprint'] || ! $binding['destination']) {
            return ['action' => 'skip', 'reason' => 'The approved outcome or platform treatments need a new comparison.'];
        }
        $variants = $test->variants;
        $current = app(CreativeTreatmentMetrics::class)->read($test->strategy, $test->started_at->toDateString(), now()->toDateString());
        foreach ($variants as &$variant) {
            $id = $variant['treatment_id'] ?? null;
            if (! $id || ! isset($current[$id])) {
                return ['action' => 'skip', 'reason' => 'Waiting for both original platform treatment IDs.'];
            }
            $variant = array_replace($variant, $current[$id]);
        }
        unset($variant);
        // Update stored variants with fresh data
        $test->update(['variants' => $variants]);

        // Check minimum sample sizes
        if (! $this->hasMinimumSampleSize($variants)) {
            return [
                'action' => 'learning',
                'reason' => 'Insufficient data for significance testing',
                'variants' => $variants,
            ];
        }

        // Asset delivery is observational. Compare primary outcomes per impression and
        // require both arms to have observed outcomes before surfacing a candidate.
        $outcomes = [];
        foreach ($variants as $variant) {
            if ($variant['conversions'] < 5 || $variant['conversions'] >= $variant['impressions']) {
                return ['action' => 'learning', 'reason' => 'More primary outcomes are needed for both treatments.'];
            }
            $outcomes[] = array_replace($variant, ['clicks' => $variant['conversions']]);
        }
        $significance = $this->chiSquaredTest($outcomes);

        if ($significance['is_significant']) {
            $selected = $this->identifyWinner($outcomes);
            $winner = collect($variants)->firstWhere('id', $selected['id']);
            $results = ['binding' => $binding['fingerprint'], 'primary_outcome' => $binding['goal'],
                'state' => 'suggested', 'comparison' => 'observational', 'chi_squared' => $significance['chi_squared'],
                'p_value' => $significance['p_value'], 'lift_pct' => $this->calculateLift($outcomes, $winner['id']),
                'metrics_snapshot' => $variants];

            $test->markSignificant($winner['id'], $significance['confidence'], $results);

            Log::info('ABTestingAgent: Test reached significance', [
                'test_id' => $test->id,
                'winner' => $winner['label'],
                'confidence' => round($significance['confidence'] * 100, 1).'%',
                'lift' => round($results['lift_pct'], 1).'%',
            ]);

            return [
                'action' => 'significant',
                'winner' => $winner,
                'confidence' => $significance['confidence'],
                'results' => $results,
            ];
        }

        return [
            'action' => 'learning',
            'reason' => 'Not yet significant',
            'chi_squared' => $significance['chi_squared'],
            'needed' => $this->chiSquaredCritical95,
            'variants' => $variants,
        ];
    }

    /**
     * Apply A/B test results — promote winner, generate replacement variations.
     */
    public function applyResults(ABTest $test): array
    {
        if ($test->status !== ABTest::STATUS_SIGNIFICANT) {
            return ['success' => false, 'reason' => 'Test has not reached significance'];
        }

        // Publishing belongs to the creative approval and readback workflow.
        return ['success' => false, 'status' => 'suggested', 'reason' => 'The measured winner is available for creative review.',
            'winner' => collect($test->variants)->firstWhere('id', $test->winning_variant_id)];
    }

    protected function hasMinimumSampleSize(array $variants): bool
    {
        $totalClicks = 0;
        foreach ($variants as $variant) {
            if (($variant['impressions'] ?? 0) < $this->minImpressionsPerVariant) {
                return false;
            }
            $totalClicks += $variant['clicks'] ?? 0;
        }

        return $totalClicks >= $this->minTotalClicks;
    }

    /**
     * Perform chi-squared test for independence on click-through rates.
     *
     * Tests H0: All variants have the same CTR
     * vs H1: At least one variant's CTR is different
     */
    protected function chiSquaredTest(array $variants): array
    {
        $k = count($variants);
        if ($k < 2) {
            return ['is_significant' => false, 'chi_squared' => 0, 'p_value' => 1, 'confidence' => 0];
        }

        // Build observed contingency table: rows = variants, cols = [clicks, non-clicks]
        $observed = [];
        $totalImpressions = 0;
        $totalClicks = 0;

        foreach ($variants as $v) {
            $impressions = max(1, $v['impressions'] ?? 0);
            $clicks = $v['clicks'] ?? 0;
            $observed[] = [$clicks, $impressions - $clicks];
            $totalImpressions += $impressions;
            $totalClicks += $clicks;
        }

        $totalNonClicks = $totalImpressions - $totalClicks;

        // Compute chi-squared statistic
        $chiSquared = 0;
        foreach ($observed as $row) {
            $rowTotal = $row[0] + $row[1];
            $expectedClicks = ($rowTotal * $totalClicks) / max(1, $totalImpressions);
            $expectedNonClicks = ($rowTotal * $totalNonClicks) / max(1, $totalImpressions);

            if ($expectedClicks > 0) {
                $chiSquared += pow($row[0] - $expectedClicks, 2) / $expectedClicks;
            }
            if ($expectedNonClicks > 0) {
                $chiSquared += pow($row[1] - $expectedNonClicks, 2) / $expectedNonClicks;
            }
        }

        // Degrees of freedom = (rows - 1) * (cols - 1) = (k - 1) * 1
        $df = $k - 1;

        // Approximate p-value using chi-squared CDF
        $pValue = $this->chiSquaredPValue($chiSquared, $df);
        $confidence = 1 - $pValue;

        // Critical values for common confidence levels (df=1 for 2 variants)
        $critical = match ($df) {
            1 => $this->chiSquaredCritical95,  // 3.841
            2 => 5.991,
            3 => 7.815,
            default => $this->chiSquaredCritical95 + ($df - 1) * 2.0, // rough approx
        };

        return [
            'is_significant' => $chiSquared >= $critical,
            'chi_squared' => round($chiSquared, 4),
            'p_value' => round($pValue, 6),
            'confidence' => round($confidence, 4),
            'df' => $df,
        ];
    }

    /**
     * Approximate chi-squared p-value using the regularized incomplete gamma function.
     * For small df values common in A/B testing, this provides good approximation.
     */
    protected function chiSquaredPValue(float $chiSquared, int $df): float
    {
        if ($chiSquared <= 0) {
            return 1.0;
        }

        // Use the regularized upper incomplete gamma function: Q(df/2, x/2)
        $a = $df / 2;
        $x = $chiSquared / 2;

        // Series expansion of the regularized incomplete gamma function
        $sum = 0;
        $term = 1 / $a;
        $sum += $term;

        for ($n = 1; $n < 200; $n++) {
            $term *= $x / ($a + $n);
            $sum += $term;
            if (abs($term) < 1e-12) {
                break;
            }
        }

        $lnGamma = $this->lnGamma($a);
        $regularized = exp($a * log($x) - $x - $lnGamma) * $sum;

        return max(0, min(1, 1 - $regularized));
    }

    /**
     * Log-gamma function using Stirling's approximation with Lanczos coefficients.
     */
    protected function lnGamma(float $x): float
    {
        if ($x <= 0) {
            return 0;
        }

        // Lanczos approximation
        $coefficients = [
            76.18009172947146,
            -86.50532032941677,
            24.01409824083091,
            -1.231739572450155,
            0.1208650973866179e-2,
            -0.5395239384953e-5,
        ];

        $y = $x;
        $tmp = $x + 5.5;
        $tmp -= ($x - 0.5) * log($tmp);
        $ser = 1.000000000190015;

        foreach ($coefficients as $i => $c) {
            $y += 1;
            $ser += $c / $y;
        }

        return -$tmp + log(2.5066282746310005 * $ser / $x);
    }

    /**
     * Identify the winning variant (highest CTR).
     */
    protected function identifyWinner(array $variants): array
    {
        $best = null;
        $bestCtr = -1;

        foreach ($variants as $variant) {
            $impressions = max(1, $variant['impressions'] ?? 0);
            $ctr = ($variant['clicks'] ?? 0) / $impressions;

            if ($ctr > $bestCtr) {
                $bestCtr = $ctr;
                $best = $variant;
            }
        }

        $best['ctr'] = round($bestCtr * 100, 2);

        return $best;
    }

    /**
     * Calculate lift percentage of winner vs average of losers.
     */
    protected function calculateLift(array $variants, string $winnerId): float
    {
        $winnerCtr = 0;
        $loserCtrs = [];

        foreach ($variants as $v) {
            $impressions = max(1, $v['impressions'] ?? 0);
            $ctr = ($v['clicks'] ?? 0) / $impressions;

            if ($v['id'] === $winnerId) {
                $winnerCtr = $ctr;
            } else {
                $loserCtrs[] = $ctr;
            }
        }

        $avgLoserCtr = count($loserCtrs) > 0 ? array_sum($loserCtrs) / count($loserCtrs) : 0;

        if ($avgLoserCtr <= 0) {
            return 0;
        }

        return (($winnerCtr - $avgLoserCtr) / $avgLoserCtr) * 100;
    }

    /**
     * Create a native Google Ads Campaign Experiment (A/B test) for a campaign.
     *
     * @param  string  $testDescription  Human-readable description for the experiment name
     * @return array{experiment_resource: string, treatment_arm_resource: string}|null
     */
    public function createGoogleNativeExperiment(Campaign $campaign, Strategy $strategy, string $testDescription): ?array
    {
        $campaignResourceName = $campaign->google_ads_campaign_id;
        if (! $campaignResourceName) {
            Log::warning('ABTestingAgent: Cannot create Google experiment — no google_ads_campaign_id', [
                'campaign_id' => $campaign->id,
            ]);

            return null;
        }

        $customer = $campaign->customer;
        $customerId = $customer?->google_ads_customer_id;
        if (! $customerId) {
            Log::warning('ABTestingAgent: Cannot create Google experiment — no google_ads_customer_id', [
                'campaign_id' => $campaign->id,
            ]);

            return null;
        }

        try {
            $experimentName = $testDescription.' — Campaign '.$campaign->id.' — '.now()->format('Y-m-d');
            $createExperiment = new CreateCampaignExperiment($customer);

            $experimentResult = ($createExperiment)(
                $customerId,
                $campaignResourceName,
                $experimentName,
                [
                    'traffic_split' => 50,
                    'start_date' => now()->addDay()->format('Y-m-d'),
                    'end_date' => now()->addDays(30)->format('Y-m-d'),
                ]
            );

            if (! $experimentResult) {
                return null;
            }

            // Persist experiment resource if column exists, otherwise log activity
            $experimentResource = $experimentResult['experiment_resource'];
            if (isset($campaign->getAttributes()['google_ads_experiment_id'])) {
                $campaign->google_ads_experiment_id = $experimentResource;
                $campaign->save();
            } else {
                AgentActivity::record(
                    'ab_testing',
                    'google_native_experiment_created',
                    "Created Google Ads experiment for campaign \"{$campaign->name}\": {$experimentResource}",
                    $customer->id,
                    $campaign->id,
                    [
                        'experiment_resource' => $experimentResource,
                        'treatment_arm_resource' => $experimentResult['treatment_arm_resource'],
                        'test_description' => $testDescription,
                    ]
                );
            }

            Log::info('ABTestingAgent: Google native experiment created', [
                'campaign_id' => $campaign->id,
                'experiment_resource' => $experimentResource,
            ]);

            return $experimentResult;
        } catch (\Throwable $e) {
            report($e);
            Log::error('ABTestingAgent: Failed to create Google native experiment', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate replacement creative variants based on the winning variant.
     */
    protected function generateReplacements(ABTest $test, array $winner, array $losers): array
    {
        $loserCount = count($losers);
        if ($loserCount === 0) {
            return [];
        }

        $typeDescription = match ($test->test_type) {
            ABTest::TYPE_HEADLINE => 'ad headlines (max 30 characters each)',
            ABTest::TYPE_DESCRIPTION => 'ad descriptions (max 90 characters each)',
            default => 'ad creative variations',
        };

        $prompt = <<<PROMPT
You are an expert ad copywriter. A winning A/B test variant has been identified.

Winning variant: "{$winner['content']}"
CTR: {$winner['ctr']}%

Losing variants:
PROMPT;

        foreach ($losers as $loser) {
            $impressions = max(1, $loser['impressions'] ?? 0);
            $ctr = round((($loser['clicks'] ?? 0) / $impressions) * 100, 2);
            $prompt .= "\n- \"{$loser['content']}\" (CTR: {$ctr}%)";
        }

        $prompt .= "\n\nGenerate {$loserCount} new {$typeDescription} that:
1. Maintain the winning tone, style, and messaging angle
2. Test different hooks, urgency cues, or value props
3. Each variation should be distinctly different from the others

Return ONLY a JSON array of strings, e.g.: [\"Variation 1\", \"Variation 2\"]";

        try {
            $response = $this->gemini->generateContent(
                model: config('ai.models.default'),
                prompt: $prompt,
                config: ['temperature' => 0.9, 'maxOutputTokens' => 1024],
            );

            if ($response && isset($response['text'])) {
                $text = $response['text'];
                // Extract JSON array from response
                if (preg_match('/\[.*\]/s', $text, $matches)) {
                    $decoded = json_decode($matches[0], true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('ABTestingAgent: Failed to generate replacements', [
                'test_id' => $test->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }
}
