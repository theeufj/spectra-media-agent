<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAdPerformanceByAsset;
use App\Services\FacebookAds\InsightService as FacebookInsightService;
use App\Services\FacebookAds\AdService as FacebookAdService;
use App\Services\Agents\AdaptiveThresholds;
use Illuminate\Support\Facades\Log;

/**
 * CreativeIntelligenceAgent
 * 
 * Analyzes creative performance at the asset level (headlines, descriptions, images).
 * Identifies winners, losers, and generates AI-powered creative variations.
 * 
 * Supported Platforms:
 * - Google Ads: RSA asset-level analysis (headlines, descriptions, images)
 * - Facebook Ads: Ad-level creative performance analysis
 * 
 * Capabilities:
 * - A/B test tracking at headline/image level
 * - Automatic winner detection
 * - AI-generated creative variations based on top performers
 * - Recommendations for underperforming assets
 */
class CreativeIntelligenceAgent
{
    protected GeminiService $gemini;
    
    // Thresholds for creative decisions
    protected array $thresholds = [
        'min_impressions_for_decision' => 1000,
        'winner_ctr_percentile' => 0.75,  // Top 25% are winners
        'loser_ctr_percentile' => 0.25,   // Bottom 25% are losers
        'min_conversions_for_winner' => 2,
        'auto_pause_min_impressions' => 2000, // Must have 2000+ impressions to auto-pause
        'auto_pause_max_ctr' => 0.005, // CTR below 0.5% with enough impressions = auto-pause candidate
    ];

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Analyze creative performance for a campaign.
     * Automatically detects platform and runs appropriate analysis.
     */
    public function analyze(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'platform' => null,
            'headlines' => [
                'winners' => [],
                'losers' => [],
                'learning' => [],
            ],
            'descriptions' => [
                'winners' => [],
                'losers' => [],
                'learning' => [],
            ],
            'images' => [
                'winners' => [],
                'losers' => [],
            ],
            'ads' => [
                'winners' => [],
                'losers' => [],
                'learning' => [],
            ],
            'recommendations' => [],
            'new_variations' => [],
            'auto_actions' => [],
        ];

        if (!$campaign->customer) {
            return $results;
        }

        // Load adaptive thresholds if customer has historical data
        if ($campaign->customer) {
            $adaptive = AdaptiveThresholds::forCustomer($campaign->customer);
            $this->thresholds['min_impressions_for_decision'] = $adaptive['min_impressions_for_decision'] ?? $this->thresholds['min_impressions_for_decision'];
            $this->thresholds['auto_pause_min_impressions'] = $adaptive['auto_pause_min_impressions'] ?? $this->thresholds['auto_pause_min_impressions'];
            $this->thresholds['auto_pause_max_ctr'] = $adaptive['auto_pause_max_ctr'] ?? $this->thresholds['auto_pause_max_ctr'];
        }

        $hasGoogle = $campaign->google_ads_campaign_id && $campaign->customer->google_ads_customer_id;
        $hasFacebook = $campaign->facebook_ads_campaign_id && $campaign->customer->facebook_ads_account_id;
        $hasMicrosoft = $campaign->microsoft_ads_campaign_id && $campaign->customer->microsoft_ads_account_id;
        $hasLinkedIn = $campaign->linkedin_campaign_id && $campaign->customer->linkedin_ads_account_id;

        // Analyze Google Ads campaign (asset-level)
        if ($hasGoogle) {
            $results['platform'] = 'google_ads';
            $this->analyzeGoogleAdsCampaign($campaign, $results);
        }

        // Analyze Facebook Ads campaign (ad-level)
        if ($hasFacebook) {
            $results['platform'] = $hasGoogle ? 'multi_platform' : 'facebook_ads';
            $this->analyzeFacebookAdsCampaign($campaign, $results);
        }

        // Analyze Microsoft Ads campaign (performance-based)
        if ($hasMicrosoft) {
            $results['platform'] = ($hasGoogle || $hasFacebook) ? 'multi_platform' : 'microsoft_ads';
            $this->analyzeMicrosoftAdsCampaign($campaign, $results);
        }

        // Analyze LinkedIn Ads campaign (performance-based)
        if ($hasLinkedIn) {
            $results['platform'] = ($hasGoogle || $hasFacebook || $hasMicrosoft) ? 'multi_platform' : 'linkedin_ads';
            $this->analyzeLinkedInAdsCampaign($campaign, $results);
        }

        return $results;
    }

    /**
     * Analyze Google Ads campaign creative performance at the asset level.
     */
    protected function analyzeGoogleAdsCampaign(Campaign $campaign, array &$results): void
    {
        $customer = $campaign->customer;
        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

        try {
            $assetService = new GetAdPerformanceByAsset($customer, true);
            $textAssets = $assetService->getResponsiveSearchAdAssets($customerId, $campaignResourceName);
            $imageAssets = $assetService->getImageAssetPerformance($customerId, $campaignResourceName);

            $results['headlines'] = $this->categorizeAssets($textAssets['headlines'] ?? []);
            $results['descriptions'] = $this->categorizeAssets($textAssets['descriptions'] ?? []);
            $results['images'] = $this->categorizeAssets($imageAssets);

            $results['recommendations'] = array_merge(
                $results['recommendations'],
                $this->generateRecommendations($results, 'google_ads')
            );

            if (!empty($results['headlines']['winners'])) {
                $results['new_variations']['headlines'] = $this->generateHeadlineVariations(
                    $results['headlines']['winners'],
                    $customer,
                    'google'
                );
            }

            if (!empty($results['descriptions']['winners'])) {
                $results['new_variations']['descriptions'] = $this->generateDescriptionVariations(
                    $results['descriptions']['winners'],
                    $customer,
                    'google'
                );
            }

            Log::info('CreativeIntelligenceAgent: Google Ads analysis complete', [
                'campaign_id' => $campaign->id,
                'headline_winners' => count($results['headlines']['winners']),
                'description_winners' => count($results['descriptions']['winners']),
            ]);
        } catch (\Exception $e) {
            Log::error('CreativeIntelligenceAgent: Google Ads analysis failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            $results['errors'][] = 'Google Ads: ' . $e->getMessage();
        }
    }

    /**
     * Analyze Facebook Ads campaign creative performance at the ad level.
     * Facebook doesn't have asset-level breakdowns like Google RSAs,
     * so we compare performance across individual ads.
     */
    protected function analyzeFacebookAdsCampaign(Campaign $campaign, array &$results): void
    {
        $customer = $campaign->customer;

        try {
            $insightService = new FacebookInsightService($customer);
            $adService = new FacebookAdService($customer);

            // Get ad-level insights for the last 30 days
            $dateEnd = now()->format('Y-m-d');
            $dateStart = now()->subDays(30)->format('Y-m-d');

            $accountId = "act_{$customer->facebook_ads_account_id}";
            $adInsights = $insightService->getAccountInsightsByLevel(
                $accountId,
                $dateStart,
                $dateEnd,
                'ad',
                ['ad_id', 'ad_name', 'impressions', 'clicks', 'spend', 'actions', 'ctr', 'cpc']
            );

            if (empty($adInsights)) {
                return;
            }

            // Normalize Facebook ad insights to the same format as Google assets
            $adPerformance = [];
            foreach ($adInsights as $insight) {
                $impressions = (int) ($insight['impressions'] ?? 0);
                $clicks = (int) ($insight['clicks'] ?? 0);
                $conversions = $insightService->parseAction($insight['actions'] ?? null, 'purchase');
                $ctr = $impressions > 0 ? $clicks / $impressions : 0;

                $adPerformance[] = [
                    'text' => $insight['ad_name'] ?? $insight['ad_id'],
                    'ad_id' => $insight['ad_id'] ?? null,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'conversions' => $conversions,
                    'ctr' => $ctr,
                    'cost' => (float) ($insight['spend'] ?? 0),
                ];
            }

            // Categorize ads into winners/losers/learning
            $results['ads'] = $this->categorizeAssets($adPerformance);

            // Generate Facebook-specific recommendations
            $fbRecommendations = [];
            $adWinners = count($results['ads']['winners']);
            $adLosers = count($results['ads']['losers']);

            if ($adLosers > 0) {
                $fbRecommendations[] = [
                    'type' => 'facebook_ad',
                    'action' => 'pause_losers',
                    'platform' => 'facebook_ads',
                    'priority' => 'high',
                    'message' => "Consider pausing {$adLosers} underperforming Facebook ad(s) and creating new variations based on winners.",
                    'assets' => array_slice($results['ads']['losers'], 0, 3),
                ];
            }

            if ($adWinners > 0 && $adLosers > 0) {
                $fbRecommendations[] = [
                    'type' => 'facebook_ad',
                    'action' => 'create_variations',
                    'platform' => 'facebook_ads',
                    'priority' => 'medium',
                    'message' => "Create new ad variations modeled after {$adWinners} winning Facebook ad(s).",
                ];
            }

            $results['recommendations'] = array_merge($results['recommendations'], $fbRecommendations);

            // Generate new ad copy variations based on winning ads
            if (!empty($results['ads']['winners'])) {
                $results['new_variations']['facebook_headlines'] = $this->generateHeadlineVariations(
                    $results['ads']['winners'],
                    $customer,
                    'facebook'
                );
                $results['new_variations']['facebook_descriptions'] = $this->generateDescriptionVariations(
                    $results['ads']['winners'],
                    $customer,
                    'facebook'
                );
            }

            // Auto-pause losing Facebook ads with statistical confidence
            // Only pause ads with 2000+ impressions, 0 conversions, and CTR below threshold
            $autoPaused = [];
            foreach ($results['ads']['losers'] as $loser) {
                if (
                    ($loser['impressions'] ?? 0) >= $this->thresholds['auto_pause_min_impressions'] &&
                    ($loser['conversions'] ?? 0) === 0 &&
                    ($loser['ctr'] ?? 0) < $this->thresholds['auto_pause_max_ctr'] &&
                    !empty($loser['ad_id'])
                ) {
                    try {
                        $adService->pauseAd($loser['ad_id']);
                        $autoPaused[] = [
                            'action' => 'paused',
                            'platform' => 'facebook_ads',
                            'ad_id' => $loser['ad_id'],
                            'ad_name' => $loser['text'],
                            'impressions' => $loser['impressions'],
                            'ctr' => round($loser['ctr'] * 100, 2) . '%',
                            'reason' => 'Auto-paused: 2000+ impressions with 0 conversions and CTR below 0.5%',
                        ];
                    } catch (\Exception $e) {
                        Log::warning('CreativeIntelligenceAgent: Failed to auto-pause Facebook ad', [
                            'ad_id' => $loser['ad_id'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            if (!empty($autoPaused)) {
                $results['auto_actions'] = array_merge($results['auto_actions'], $autoPaused);
                Log::info('CreativeIntelligenceAgent: Auto-paused Facebook ads', [
                    'campaign_id' => $campaign->id,
                    'count' => count($autoPaused),
                ]);
            }

            Log::info('CreativeIntelligenceAgent: Facebook Ads analysis complete', [
                'campaign_id' => $campaign->id,
                'ad_winners' => $adWinners,
                'ad_losers' => $adLosers,
            ]);
        } catch (\Exception $e) {
            Log::error('CreativeIntelligenceAgent: Facebook Ads analysis failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            $results['errors'][] = 'Facebook Ads: ' . $e->getMessage();
        }
    }

    /**
     * Categorize assets into winners, losers, and learning.
     */
    protected function categorizeAssets(array $assets): array
    {
        $categorized = [
            'winners' => [],
            'losers' => [],
            'learning' => [],
        ];

        if (empty($assets)) {
            return $categorized;
        }

        // Filter assets with enough impressions
        $qualifiedAssets = array_filter($assets, fn($a) => 
            $a['impressions'] >= $this->thresholds['min_impressions_for_decision']
        );

        if (empty($qualifiedAssets)) {
            // All assets still learning
            $categorized['learning'] = $assets;
            return $categorized;
        }

        // Calculate percentile thresholds
        $ctrs = array_column($qualifiedAssets, 'ctr');
        sort($ctrs);
        
        $winnerThreshold = $this->getPercentile($ctrs, $this->thresholds['winner_ctr_percentile']);
        $loserThreshold = $this->getPercentile($ctrs, $this->thresholds['loser_ctr_percentile']);

        foreach ($assets as $asset) {
            // Not enough data yet
            if ($asset['impressions'] < $this->thresholds['min_impressions_for_decision']) {
                $categorized['learning'][] = $asset;
                continue;
            }

            // Winners: High CTR or good conversions
            if ($asset['ctr'] >= $winnerThreshold || 
                $asset['conversions'] >= $this->thresholds['min_conversions_for_winner']) {
                $asset['status'] = 'winner';
                $categorized['winners'][] = $asset;
            }
            // Losers: Low CTR and no conversions
            elseif ($asset['ctr'] <= $loserThreshold && $asset['conversions'] == 0) {
                $asset['status'] = 'loser';
                $categorized['losers'][] = $asset;
            }
            // Middle performers stay in learning
            else {
                $asset['status'] = 'learning';
                $categorized['learning'][] = $asset;
            }
        }

        return $categorized;
    }

    /**
     * Get percentile value from sorted array.
     */
    protected function getPercentile(array $sorted, float $percentile): float
    {
        $count = count($sorted);
        if ($count === 0) return 0;
        
        $index = (int) floor($percentile * ($count - 1));
        return $sorted[$index];
    }

    /**
     * Generate recommendations based on analysis.
     */
    protected function generateRecommendations(array $analysis, string $platform = 'google_ads'): array
    {
        $recommendations = [];

        // Headline recommendations
        $headlineWinners = count($analysis['headlines']['winners']);
        $headlineLosers = count($analysis['headlines']['losers']);

        if ($headlineLosers > 2) {
            $recommendations[] = [
                'type' => 'headline',
                'action' => 'replace_losers',
                'priority' => 'high',
                'message' => "Replace {$headlineLosers} underperforming headlines with variations of your winners.",
                'assets' => array_slice($analysis['headlines']['losers'], 0, 3),
            ];
        }

        if ($headlineWinners > 0 && count($analysis['headlines']['learning']) < 3) {
            $recommendations[] = [
                'type' => 'headline',
                'action' => 'add_variations',
                'priority' => 'medium',
                'message' => "Add more headline variations based on your {$headlineWinners} winning headlines.",
            ];
        }

        // Description recommendations
        $descWinners = count($analysis['descriptions']['winners']);
        $descLosers = count($analysis['descriptions']['losers']);

        if ($descLosers > 1) {
            $recommendations[] = [
                'type' => 'description',
                'action' => 'replace_losers',
                'priority' => 'high',
                'message' => "Replace {$descLosers} underperforming descriptions.",
                'assets' => array_slice($analysis['descriptions']['losers'], 0, 2),
            ];
        }

        // Image recommendations
        $imageLosers = count($analysis['images']['losers'] ?? []);
        if ($imageLosers > 0) {
            $recommendations[] = [
                'type' => 'image',
                'action' => 'replace_images',
                'priority' => 'medium',
                'message' => "Consider replacing {$imageLosers} underperforming images.",
            ];
        }

        return $recommendations;
    }

    /**
     * Generate new headline variations based on winners.
     */
    protected function generateHeadlineVariations(array $winners, Customer $customer, string $platform = 'google'): array
    {
        if (empty($winners)) {
            return [];
        }

        $winningHeadlines = array_column($winners, 'text');
        $winningList = implode("\n- ", $winningHeadlines);

        $brandContext = '';
        if ($customer->brandGuideline) {
            $brandContext = "Brand Voice: " . ($customer->brandGuideline->brand_voice ?? 'professional') . "\n";
            $brandContext .= "USPs: " . implode(', ', $customer->brandGuideline->unique_selling_propositions ?? []);
        }

        $maxChars = $platform === 'facebook' ? 40 : 30;
        $platformName = $platform === 'facebook' ? 'Facebook Ads' : 'Google Ads';

        $prompt = <<<PROMPT
Generate 5 new headline variations for {$platformName} based on these winning headlines:
- {$winningList}

{$brandContext}

Requirements:
1. Maximum {$maxChars} characters each
2. Maintain the tone and style of winners
3. Try different angles while keeping what works
4. Include a call to action where appropriate

Return as JSON array of strings:
["headline 1", "headline 2", "headline 3", "headline 4", "headline 5"]
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                'gemini-3.1-flash-lite-preview',
                $prompt,
                ['responseMimeType' => 'application/json']
            );

            if ($response && isset($response['text'])) {
                return json_decode($response['text'], true) ?? [];
            }
        } catch (\Exception $e) {
            Log::warning('CreativeIntelligenceAgent: Failed to generate headlines', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Generate new description variations based on winners.
     */
    protected function generateDescriptionVariations(array $winners, Customer $customer, string $platform = 'google'): array
    {
        if (empty($winners)) {
            return [];
        }

        $winningDescriptions = array_column($winners, 'text');
        $winningList = implode("\n- ", $winningDescriptions);

        $brandContext = '';
        if ($customer->brandGuideline) {
            $brandContext = "Brand Voice: " . ($customer->brandGuideline->brand_voice ?? 'professional') . "\n";
        }

        $maxChars = $platform === 'facebook' ? 125 : 90;
        $platformName = $platform === 'facebook' ? 'Facebook Ads' : 'Google Ads';

        $prompt = <<<PROMPT
Generate 3 new description variations for {$platformName} based on these winning descriptions:
- {$winningList}

{$brandContext}

Requirements:
1. Maximum {$maxChars} characters each
2. Maintain the persuasive elements that make winners successful
3. Include benefits and call-to-action
4. Vary the approach while keeping core message

Return as JSON array of strings:
["description 1", "description 2", "description 3"]
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                'gemini-3.1-flash-lite-preview',
                $prompt,
                ['responseMimeType' => 'application/json']
            );

            if ($response && isset($response['text'])) {
                return json_decode($response['text'], true) ?? [];
            }
        } catch (\Exception $e) {
            Log::warning('CreativeIntelligenceAgent: Failed to generate descriptions', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Analyze Microsoft Ads campaign performance for creative insights.
     * Uses stored performance data to detect trends and generate recommendations.
     */
    protected function analyzeMicrosoftAdsCampaign(Campaign $campaign, array &$results): void
    {
        try {
            $data = \App\Models\MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', '>=', now()->subDays(30)->toDateString())
                ->orderBy('date')
                ->get();

            if ($data->isEmpty()) return;

            $totalImpressions = $data->sum('impressions');
            $totalClicks = $data->sum('clicks');
            $avgCtr = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : 0;

            // Check for declining CTR trend (creative fatigue indicator)
            $firstHalf = $data->take((int) floor($data->count() / 2));
            $secondHalf = $data->skip((int) floor($data->count() / 2));

            $firstCtr = $firstHalf->sum('impressions') > 0
                ? ($firstHalf->sum('clicks') / $firstHalf->sum('impressions')) * 100
                : 0;
            $secondCtr = $secondHalf->sum('impressions') > 0
                ? ($secondHalf->sum('clicks') / $secondHalf->sum('impressions')) * 100
                : 0;

            if ($firstCtr > 0 && $secondCtr < $firstCtr * 0.75) {
                $results['recommendations'][] = [
                    'type' => 'creative_fatigue',
                    'platform' => 'microsoft_ads',
                    'severity' => 'high',
                    'message' => 'Microsoft Ads CTR declining - possible creative fatigue',
                    'details' => sprintf('CTR dropped from %.2f%% to %.2f%% over 30 days', $firstCtr, $secondCtr),
                    'suggestion' => 'Refresh ad copy and test new headlines/descriptions',
                ];
            }

            $results['microsoft_ads_summary'] = [
                'impressions' => $totalImpressions,
                'clicks' => $totalClicks,
                'avg_ctr' => round($avgCtr, 2),
                'days_analyzed' => $data->count(),
            ];
        } catch (\Exception $e) {
            Log::warning('CreativeIntelligenceAgent: Microsoft Ads analysis failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Analyze LinkedIn Ads campaign performance for creative insights.
     * Uses stored performance data to detect trends and generate recommendations.
     */
    protected function analyzeLinkedInAdsCampaign(Campaign $campaign, array &$results): void
    {
        try {
            $data = \App\Models\LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', '>=', now()->subDays(30)->toDateString())
                ->orderBy('date')
                ->get();

            if ($data->isEmpty()) return;

            $totalImpressions = $data->sum('impressions');
            $totalClicks = $data->sum('clicks');
            $avgCtr = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : 0;

            // LinkedIn B2B benchmarks are lower - adjust thresholds
            if ($totalImpressions > 1000 && $avgCtr < 0.3) {
                $results['recommendations'][] = [
                    'type' => 'low_ctr',
                    'platform' => 'linkedin_ads',
                    'severity' => 'medium',
                    'message' => 'LinkedIn Ads CTR below B2B benchmark',
                    'details' => sprintf('Average CTR: %.2f%% (benchmark: 0.3-0.5%%)', $avgCtr),
                    'suggestion' => 'Test more compelling ad copy, professional imagery, and stronger CTAs for B2B audience',
                ];
            }

            // Check for declining performance trend
            $firstHalf = $data->take((int) floor($data->count() / 2));
            $secondHalf = $data->skip((int) floor($data->count() / 2));

            $firstCtr = $firstHalf->sum('impressions') > 0
                ? ($firstHalf->sum('clicks') / $firstHalf->sum('impressions')) * 100
                : 0;
            $secondCtr = $secondHalf->sum('impressions') > 0
                ? ($secondHalf->sum('clicks') / $secondHalf->sum('impressions')) * 100
                : 0;

            if ($firstCtr > 0 && $secondCtr < $firstCtr * 0.75) {
                $results['recommendations'][] = [
                    'type' => 'creative_fatigue',
                    'platform' => 'linkedin_ads',
                    'severity' => 'high',
                    'message' => 'LinkedIn Ads creative fatigue detected',
                    'details' => sprintf('CTR dropped from %.2f%% to %.2f%% over 30 days', $firstCtr, $secondCtr),
                    'suggestion' => 'Rotate creative assets and test new messaging angles for B2B audience',
                ];
            }

            $results['linkedin_ads_summary'] = [
                'impressions' => $totalImpressions,
                'clicks' => $totalClicks,
                'avg_ctr' => round($avgCtr, 2),
                'days_analyzed' => $data->count(),
            ];
        } catch (\Exception $e) {
            Log::warning('CreativeIntelligenceAgent: LinkedIn Ads analysis failed', ['error' => $e->getMessage()]);
        }
    }
}
