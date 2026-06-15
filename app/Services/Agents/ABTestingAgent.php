<?php

namespace App\Services\Agents;

use App\Models\ABTest;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAdPerformanceByAsset;
use App\Services\GoogleAds\CommonServices\CreateCampaignExperiment;
use App\Services\GoogleAds\CommonServices\GetCampaignExperimentResults;
use App\Services\GoogleAds\CommonServices\PromoteCampaignExperiment;
use App\Services\FacebookAds\InsightService as FacebookInsightService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\AdCopy;

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
                'label' => $variant['label'] ?? "Variant " . chr(65 + $index), // A, B, C...
                'content' => $variant['content'],
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
            'started_at' => now(),
        ]);
    }

    /**
     * Evaluate a running A/B test for statistical significance.
     */
    public function evaluateTest(ABTest $test): array
    {
        if (!$test->isRunning()) {
            return ['action' => 'skip', 'reason' => 'Test is not running'];
        }

        // Check max duration
        if ($test->started_at->diffInDays(now()) > $this->maxTestDurationDays) {
            $test->markStopped();
            return ['action' => 'stopped', 'reason' => 'Maximum test duration exceeded'];
        }

        // Refresh variant metrics from the ad platform
        $variants = $this->refreshVariantMetrics($test);
        if (!$variants) {
            return ['action' => 'skip', 'reason' => 'Could not refresh metrics'];
        }

        // Update stored variants with fresh data
        $test->update(['variants' => $variants]);

        // Check minimum sample sizes
        if (!$this->hasMinimumSampleSize($variants)) {
            return [
                'action' => 'learning',
                'reason' => 'Insufficient data for significance testing',
                'variants' => $variants,
            ];
        }

        // Run chi-squared test on CTR
        $significance = $this->chiSquaredTest($variants);

        if ($significance['is_significant']) {
            $winner = $this->identifyWinner($variants);

            $results = [
                'chi_squared' => $significance['chi_squared'],
                'p_value' => $significance['p_value'],
                'lift_pct' => $this->calculateLift($variants, $winner['id']),
                'metrics_snapshot' => $variants,
            ];

            $test->markSignificant($winner['id'], $significance['confidence'], $results);

            Log::info('ABTestingAgent: Test reached significance', [
                'test_id' => $test->id,
                'winner' => $winner['label'],
                'confidence' => round($significance['confidence'] * 100, 1) . '%',
                'lift' => round($results['lift_pct'], 1) . '%',
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

        $variants = $test->variants;
        $winnerId = $test->winning_variant_id;
        $winner = collect($variants)->firstWhere('id', $winnerId);
        $losers = collect($variants)->where('id', '!=', $winnerId)->all();

        // Generate replacement creatives based on the winner
        $replacements = $this->generateReplacements($test, $winner, $losers);

        // Persist winner + replacements back to the strategy's AdCopy model
        $this->persistWinnerToAdCopy($test, $winner, $replacements);

        $test->markApplied();

        Log::info('ABTestingAgent: Results applied', [
            'test_id' => $test->id,
            'winner' => $winner['label'],
            'losers_replaced' => count($losers),
            'new_variations' => count($replacements),
        ]);

        return [
            'success' => true,
            'winner' => $winner,
            'replacements' => $replacements,
        ];
    }

    /**
     * Save the winning variant's headlines (plus AI-generated replacements) back to the
     * strategy's AdCopy record so the next test cycle and any future re-deployments use
     * the confirmed winner copy.
     */
    protected function persistWinnerToAdCopy(ABTest $test, array $winner, array $replacements): void
    {
        if ($test->test_type !== ABTest::TYPE_HEADLINE && $test->test_type !== ABTest::TYPE_DESCRIPTION) {
            return;
        }

        $strategy = $test->strategy;
        if (!$strategy) {
            return;
        }

        $adCopy = AdCopy::where('strategy_id', $strategy->id)->first();
        if (!$adCopy) {
            return;
        }

        $winnerHeadlines    = $winner['meta']['headlines']    ?? explode(' | ', $winner['content'] ?? '');
        $winnerDescriptions = $winner['meta']['descriptions'] ?? $adCopy->descriptions ?? [];

        if ($test->test_type === ABTest::TYPE_HEADLINE) {
            // Replace full headline set: winner headlines first, then AI-generated replacements
            $updatedHeadlines = array_values(array_unique(array_merge(
                $winnerHeadlines,
                array_filter($replacements),
            )));
            // Cap at Google's RSA limit of 15 headlines
            $updatedHeadlines = array_slice($updatedHeadlines, 0, 15);
            $adCopy->update(['headlines' => $updatedHeadlines]);
        } elseif ($test->test_type === ABTest::TYPE_DESCRIPTION) {
            $winnerDescriptions = $winner['meta']['descriptions'] ?? explode(' | ', $winner['content'] ?? '');
            $updatedDescriptions = array_values(array_unique(array_merge(
                $winnerDescriptions,
                array_filter($replacements),
            )));
            $updatedDescriptions = array_slice($updatedDescriptions, 0, 4);
            $adCopy->update(['descriptions' => $updatedDescriptions]);
        }

        Log::info('ABTestingAgent: AdCopy updated with winner', [
            'test_id'    => $test->id,
            'strategy_id' => $strategy->id,
            'ad_copy_id' => $adCopy->id,
            'test_type'  => $test->test_type,
        ]);
    }

    /**
     * Refresh variant metrics from the ad platform.
     * Supports Google Ads (asset-level) and Facebook Ads (ad-level).
     */
    protected function refreshVariantMetrics(ABTest $test): ?array
    {
        $campaign = $test->campaign;
        if (!$campaign || !$campaign->customer) {
            return null;
        }

        $customer = $campaign->customer;

        // Try Google Ads first
        if ($campaign->google_ads_campaign_id && $customer->google_ads_customer_id) {
            return $this->refreshGoogleVariantMetrics($test, $campaign, $customer);
        }

        // Try Facebook Ads
        if ($campaign->facebook_ads_campaign_id && $customer->facebook_ads_account_id) {
            return $this->refreshFacebookVariantMetrics($test, $campaign, $customer);
        }

        return $test->variants;
    }

    /**
     * Refresh variant metrics from Google Ads asset-level data.
     */
    protected function refreshGoogleVariantMetrics(ABTest $test, Campaign $campaign, $customer): ?array
    {
        try {
            $customerId = $customer->google_ads_customer_id;
            $campaignResourceName = $campaign->googleAdsResourceName();
            $assetService = new GetAdPerformanceByAsset($customer, true);

            $fieldKey = match ($test->test_type) {
                ABTest::TYPE_HEADLINE, ABTest::TYPE_DESCRIPTION => 'text',
                ABTest::TYPE_IMAGE => 'image',
                default => 'text',
            };

            if ($fieldKey === 'text') {
                $platformAssets = $assetService->getResponsiveSearchAdAssets($customerId, $campaignResourceName);
                $assetPool = $test->test_type === ABTest::TYPE_HEADLINE
                    ? ($platformAssets['headlines'] ?? [])
                    : ($platformAssets['descriptions'] ?? []);
            } else {
                $assetPool = $assetService->getImageAssetPerformance($customerId, $campaignResourceName);
            }

            $variants = $test->variants;
            foreach ($variants as &$variant) {
                foreach ($assetPool as $asset) {
                    $assetContent = $asset['text'] ?? $asset['asset_name'] ?? '';
                    if ($assetContent === $variant['content']) {
                        $variant['impressions'] = $asset['impressions'] ?? $variant['impressions'];
                        $variant['clicks'] = $asset['clicks'] ?? $variant['clicks'];
                        $variant['conversions'] = $asset['conversions'] ?? $variant['conversions'];
                        $variant['cost'] = $asset['cost'] ?? $variant['cost'];
                        break;
                    }
                }
            }

            return $variants;
        } catch (\Exception $e) {
            Log::warning('ABTestingAgent: Failed to refresh Google metrics', [
                'test_id' => $test->id,
                'error' => $e->getMessage(),
            ]);
            return $test->variants;
        }
    }

    /**
     * Refresh variant metrics from Facebook Ads ad-level insights.
     * Facebook A/B tests compare at the ad level rather than asset level.
     */
    protected function refreshFacebookVariantMetrics(ABTest $test, Campaign $campaign, $customer): ?array
    {
        try {
            $insightService = new FacebookInsightService($customer);
            $accountId = "act_{$customer->facebook_ads_account_id}";
            $dateEnd = now()->format('Y-m-d');
            $dateStart = $test->started_at->format('Y-m-d');

            $adInsights = $insightService->getAccountInsightsByLevel(
                $accountId,
                $dateStart,
                $dateEnd,
                'ad',
                ['ad_id', 'ad_name', 'impressions', 'clicks', 'spend', 'actions']
            );

            if (empty($adInsights)) {
                return $test->variants;
            }

            // Build lookup by ad name/id
            $insightLookup = [];
            foreach ($adInsights as $insight) {
                $key = $insight['ad_name'] ?? $insight['ad_id'] ?? '';
                if (!isset($insightLookup[$key])) {
                    $insightLookup[$key] = [
                        'impressions' => 0,
                        'clicks' => 0,
                        'conversions' => 0,
                        'cost' => 0,
                    ];
                }
                $insightLookup[$key]['impressions'] += (int) ($insight['impressions'] ?? 0);
                $insightLookup[$key]['clicks'] += (int) ($insight['clicks'] ?? 0);
                $insightLookup[$key]['conversions'] += $insightService->parseAction($insight['actions'] ?? null, 'purchase');
                $insightLookup[$key]['cost'] += (float) ($insight['spend'] ?? 0);
            }

            // Match insights back to variants
            $variants = $test->variants;
            foreach ($variants as &$variant) {
                $content = $variant['content'] ?? '';
                if (isset($insightLookup[$content])) {
                    $variant['impressions'] = $insightLookup[$content]['impressions'];
                    $variant['clicks'] = $insightLookup[$content]['clicks'];
                    $variant['conversions'] = $insightLookup[$content]['conversions'];
                    $variant['cost'] = $insightLookup[$content]['cost'];
                }
            }

            return $variants;
        } catch (\Exception $e) {
            Log::warning('ABTestingAgent: Failed to refresh Facebook metrics', [
                'test_id' => $test->id,
                'error' => $e->getMessage(),
            ]);
            return $test->variants;
        }
    }

    /**
     * Check if all variants have minimum sample size.
     */
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
        if ($chiSquared <= 0) return 1.0;

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
            if (abs($term) < 1e-12) break;
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
        if ($x <= 0) return 0;

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

        if ($avgLoserCtr <= 0) return 0;

        return (($winnerCtr - $avgLoserCtr) / $avgLoserCtr) * 100;
    }

    /**
     * Create a native Google Ads Campaign Experiment (A/B test) for a campaign.
     *
     * @param  Campaign  $campaign
     * @param  Strategy  $strategy
     * @param  string    $testDescription  Human-readable description for the experiment name
     * @return array{experiment_resource: string, treatment_arm_resource: string}|null
     */
    public function createGoogleNativeExperiment(Campaign $campaign, Strategy $strategy, string $testDescription): ?array
    {
        $campaignResourceName = $campaign->google_ads_campaign_id;
        if (!$campaignResourceName) {
            Log::warning('ABTestingAgent: Cannot create Google experiment — no google_ads_campaign_id', [
                'campaign_id' => $campaign->id,
            ]);
            return null;
        }

        $customer   = $campaign->customer;
        $customerId = $customer?->google_ads_customer_id;
        if (!$customerId) {
            Log::warning('ABTestingAgent: Cannot create Google experiment — no google_ads_customer_id', [
                'campaign_id' => $campaign->id,
            ]);
            return null;
        }

        try {
            $experimentName = $testDescription . ' — Campaign ' . $campaign->id . ' — ' . now()->format('Y-m-d');
            $createExperiment = new CreateCampaignExperiment($customer);

            $experimentResult = ($createExperiment)(
                $customerId,
                $campaignResourceName,
                $experimentName,
                [
                    'traffic_split' => 50,
                    'start_date'    => now()->addDay()->format('Y-m-d'),
                    'end_date'      => now()->addDays(30)->format('Y-m-d'),
                ]
            );

            if (!$experimentResult) {
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
                        'experiment_resource'    => $experimentResource,
                        'treatment_arm_resource' => $experimentResult['treatment_arm_resource'],
                        'test_description'       => $testDescription,
                    ]
                );
            }

            Log::info('ABTestingAgent: Google native experiment created', [
                'campaign_id'         => $campaign->id,
                'experiment_resource' => $experimentResource,
            ]);

            return $experimentResult;
        } catch (\Exception $e) {
            Log::error('ABTestingAgent: Failed to create Google native experiment', [
                'campaign_id' => $campaign->id,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check the status of a running Google Ads experiment and promote it if conditions are met.
     *
     * Promotes when:
     *  - Status is GRADUATED, OR
     *  - Treatment arm shows >10% conversion rate improvement with >100 conversions
     *
     * @param  Campaign $campaign
     * @return string|null  'promoted' | 'running' | 'insufficient_data' | null on error
     */
    public function checkAndPromoteExperiment(Campaign $campaign): ?string
    {
        $customer   = $campaign->customer;
        $customerId = $customer?->google_ads_customer_id;

        // Retrieve the experiment resource — check attribute directly to avoid errors
        // if the column hasn't been added to the campaigns table yet.
        $experimentResource = null;
        try {
            $experimentResource = $campaign->google_ads_experiment_id ?? null;
        } catch (\Exception) {
            // Column may not exist
        }

        if (!$experimentResource || !$customerId) {
            Log::warning('ABTestingAgent: checkAndPromoteExperiment — missing experiment resource or customer ID', [
                'campaign_id'         => $campaign->id,
                'experiment_resource' => $experimentResource,
            ]);
            return null;
        }

        try {
            $getResults = new GetCampaignExperimentResults($customer);
            $results    = ($getResults)($customerId, $experimentResource);

            $status = $results['experiment_status'] ?? 'UNKNOWN';

            // Auto-promote if Google already graduated the experiment
            if ($status === 'GRADUATED') {
                $promote = new PromoteCampaignExperiment($customer);
                $promoted = ($promote)($customerId, $experimentResource);
                return $promoted ? 'promoted' : 'running';
            }

            // Check if treatment arm outperforms control by >10% with >100 conversions
            $arms = $results['arms'] ?? [];
            if (count($arms) >= 2) {
                // Arms are returned in operation order: index 0 = control, index 1 = treatment
                $controlArm   = $arms[0] ?? null;
                $treatmentArm = $arms[count($arms) - 1] ?? null;

                if ($controlArm && $treatmentArm) {
                    $treatmentConversions = (float) ($treatmentArm['conversions'] ?? 0);
                    $controlConversions   = (float) ($controlArm['conversions'] ?? 0);

                    if ($treatmentConversions > 100 && $controlConversions > 0) {
                        $lift = ($treatmentConversions - $controlConversions) / $controlConversions;

                        if ($lift > 0.10) {
                            $promote  = new PromoteCampaignExperiment($customer);
                            $promoted = ($promote)($customerId, $experimentResource);

                            Log::info('ABTestingAgent: Promoting experiment due to >10% conversion lift', [
                                'campaign_id'         => $campaign->id,
                                'experiment_resource' => $experimentResource,
                                'lift_pct'            => round($lift * 100, 1),
                                'treatment_conversions' => $treatmentConversions,
                            ]);

                            return $promoted ? 'promoted' : 'running';
                        }
                    }

                    if ($treatmentConversions <= 100) {
                        return 'insufficient_data';
                    }
                }
            }

            return 'running';
        } catch (\Exception $e) {
            Log::error('ABTestingAgent: checkAndPromoteExperiment failed', [
                'campaign_id'         => $campaign->id,
                'experiment_resource' => $experimentResource,
                'error'               => $e->getMessage(),
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
        if ($loserCount === 0) return [];

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
        } catch (\Exception $e) {
            Log::warning('ABTestingAgent: Failed to generate replacements', [
                'test_id' => $test->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }
}
