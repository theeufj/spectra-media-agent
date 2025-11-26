<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetAdPerformanceByAsset;
use Illuminate\Support\Facades\Log;

/**
 * CreativeIntelligenceAgent
 * 
 * Analyzes creative performance at the asset level (headlines, descriptions, images).
 * Identifies winners, losers, and generates AI-powered creative variations.
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
    ];

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Analyze creative performance for a campaign.
     */
    public function analyze(Campaign $campaign): array
    {
        $results = [
            'campaign_id' => $campaign->id,
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
            'recommendations' => [],
            'new_variations' => [],
        ];

        if (!$campaign->google_ads_campaign_id || !$campaign->customer) {
            return $results;
        }

        $customer = $campaign->customer;
        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

        try {
            // Get asset performance data
            $assetService = new GetAdPerformanceByAsset($customer, true);
            $textAssets = $assetService->getResponsiveSearchAdAssets($customerId, $campaignResourceName);
            $imageAssets = $assetService->getImageAssetPerformance($customerId, $campaignResourceName);

            // Analyze headlines
            $results['headlines'] = $this->categorizeAssets($textAssets['headlines'] ?? []);
            
            // Analyze descriptions
            $results['descriptions'] = $this->categorizeAssets($textAssets['descriptions'] ?? []);
            
            // Analyze images
            $results['images'] = $this->categorizeAssets($imageAssets);

            // Generate recommendations
            $results['recommendations'] = $this->generateRecommendations($results);

            // Generate new variations based on winners
            if (!empty($results['headlines']['winners'])) {
                $results['new_variations']['headlines'] = $this->generateHeadlineVariations(
                    $results['headlines']['winners'],
                    $customer
                );
            }

            if (!empty($results['descriptions']['winners'])) {
                $results['new_variations']['descriptions'] = $this->generateDescriptionVariations(
                    $results['descriptions']['winners'],
                    $customer
                );
            }

            Log::info('CreativeIntelligenceAgent: Analysis complete', [
                'campaign_id' => $campaign->id,
                'headline_winners' => count($results['headlines']['winners']),
                'description_winners' => count($results['descriptions']['winners']),
                'recommendations' => count($results['recommendations']),
            ]);

        } catch (\Exception $e) {
            Log::error('CreativeIntelligenceAgent: Analysis failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            $results['error'] = $e->getMessage();
        }

        return $results;
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
    protected function generateRecommendations(array $analysis): array
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
    protected function generateHeadlineVariations(array $winners, Customer $customer): array
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

        $prompt = <<<PROMPT
Generate 5 new headline variations for Google Ads based on these winning headlines:
- {$winningList}

{$brandContext}

Requirements:
1. Maximum 30 characters each
2. Maintain the tone and style of winners
3. Try different angles while keeping what works
4. Include a call to action where appropriate

Return as JSON array of strings:
["headline 1", "headline 2", "headline 3", "headline 4", "headline 5"]
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                'gemini-2.0-flash-lite',
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
    protected function generateDescriptionVariations(array $winners, Customer $customer): array
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

        $prompt = <<<PROMPT
Generate 3 new description variations for Google Ads based on these winning descriptions:
- {$winningList}

{$brandContext}

Requirements:
1. Maximum 90 characters each
2. Maintain the persuasive elements that make winners successful
3. Include benefits and call-to-action
4. Vary the approach while keeping core message

Return as JSON array of strings:
["description 1", "description 2", "description 3"]
PROMPT;

        try {
            $response = $this->gemini->generateContent(
                'gemini-2.0-flash-lite',
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
}
