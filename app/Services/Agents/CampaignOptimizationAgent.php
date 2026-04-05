<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\CommonServices\UpdateKeywordBid;
use App\Services\GoogleAds\CommonServices\UpdateKeywordStatus;
use App\Services\GoogleAds\CommonServices\RemoveKeyword;
use App\Services\GoogleAds\CommonServices\SetDeviceBidAdjustment;
use App\Services\GoogleAds\CommonServices\SetLocationBidAdjustment;
use App\Services\GoogleAds\CommonServices\SetAdSchedule;
use App\Services\GoogleAds\CommonServices\CreateStructuredSnippetAsset;
use App\Services\GoogleAds\CommonServices\CreateCallAsset;
use App\Services\GoogleAds\CommonServices\CreatePriceAsset;
use App\Services\GoogleAds\CommonServices\CreatePromotionAsset;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use App\Services\GoogleAds\CommonServices\CreateUserList;
use App\Services\GoogleAds\CommonServices\CreateRemarketingAudience;
use App\Models\Audience;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use App\Services\FacebookAds\InsightService;
use App\Models\GoogleAdsPerformanceData;
use App\Prompts\OptimizationPrompt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * CampaignOptimizationAgent
 * 
 * AI-powered campaign optimization agent that analyzes performance data
 * and generates actionable recommendations with confidence scoring.
 * 
 * Features:
 * - Cross-platform optimization (Google Ads, Facebook Ads)
 * - Confidence scoring for all recommendations
 * - Historical performance comparison
 * - Auto-apply high-confidence optimizations
 * - Human review queue for low-confidence suggestions
 */
class CampaignOptimizationAgent
{
    protected GeminiService $gemini;
    protected GetCampaignPerformance $getGoogleCampaignPerformance;
    
    // Confidence thresholds
    protected float $autoApplyThreshold = 0.85; // Auto-apply if confidence >= 85%
    protected float $reviewRequiredThreshold = 0.60; // Requires review if confidence < 60%
    
    // Minimum data requirements for confident recommendations
    protected int $minImpressionsForBidRecommendation = 1000;
    protected int $minClicksForCtrAnalysis = 100;
    protected int $minConversionsForCpaAnalysis = 15;

    public function __construct(GeminiService $gemini, GetCampaignPerformance $getGoogleCampaignPerformance)
    {
        $this->gemini = $gemini;
        $this->getGoogleCampaignPerformance = $getGoogleCampaignPerformance;
    }

    /**
     * Analyze a campaign and generate recommendations with confidence scores.
     *
     * @param Campaign $campaign
     * @return array|null Analysis results with scored recommendations
     */
    public function analyze(Campaign $campaign): ?array
    {
        $metrics = null;
        $platform = null;
        $historicalMetrics = null;

        // Determine platform and fetch metrics
        if ($campaign->google_ads_campaign_id && $campaign->customer_id) {
            $platform = 'Google Ads';
            $metrics = $this->getGoogleMetrics($campaign);
            $historicalMetrics = $this->getGoogleHistoricalMetrics($campaign);
        } elseif ($campaign->facebook_ads_campaign_id && $campaign->customer) {
            $platform = 'Facebook Ads';
            $metrics = $this->getFacebookMetrics($campaign);
            $historicalMetrics = $this->getFacebookHistoricalMetrics($campaign);
        }

        if (!$metrics) {
            Log::info("No performance data found for campaign {$campaign->id} ({$platform}).");
            return null;
        }

        // Calculate data quality score
        $dataQuality = $this->assessDataQuality($metrics);
        
        // Prepare enhanced campaign data
        $campaignData = [
            'name' => $campaign->name,
            'platform' => $platform,
            'goals' => $campaign->goals,
            'total_budget' => $campaign->total_budget,
            'daily_budget' => $campaign->daily_budget,
            'primary_kpi' => $campaign->primary_kpi,
            'product_focus' => $campaign->product_focus,
            'data_quality_score' => $dataQuality['score'],
            'data_quality_notes' => $dataQuality['notes'],
        ];

        // Generate AI recommendations
        $prompt = OptimizationPrompt::generate($campaignData, $metrics, $historicalMetrics);

        try {
            $response = $this->gemini->generateContent(
                model: 'gemini-3-flash-preview',
                prompt: $prompt,
                config: [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 4096,
                ]
            );
            
            if (!$response || !isset($response['text'])) {
                Log::error("Empty AI response for campaign {$campaign->id}");
                return null;
            }

            // Extract JSON from response
            if (preg_match('/\{.*\}/s', $response['text'], $matches)) {
                $json = $matches[0];
                $recommendations = json_decode($json, true);
                
                if ($recommendations) {
                    // Enhance recommendations with confidence scores
                    $recommendations = $this->enhanceWithConfidenceScores(
                        $recommendations,
                        $metrics,
                        $historicalMetrics,
                        $dataQuality
                    );
                    
                    // Categorize by confidence level
                    $recommendations['categorized'] = $this->categorizeRecommendations($recommendations);
                    
                    // Cache recommendations
                    $this->cacheRecommendations($campaign->id, $recommendations);
                    
                    return $recommendations;
                }
            }
            
            Log::error("Failed to parse AI response for campaign {$campaign->id}");
            return null;

        } catch (\Exception $e) {
            Log::error("Optimization Agent failed for campaign {$campaign->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Assess the quality of available data for making recommendations.
     */
    protected function assessDataQuality(array $metrics): array
    {
        $score = 100;
        $notes = [];
        
        $impressions = $metrics['impressions'] ?? 0;
        $clicks = $metrics['clicks'] ?? 0;
        $conversions = $metrics['conversions'] ?? 0;
        
        // Penalize for low impressions
        if ($impressions < 1000) {
            $score -= 30;
            $notes[] = 'Low impression volume reduces recommendation confidence';
        } elseif ($impressions < 5000) {
            $score -= 15;
            $notes[] = 'Moderate impression volume - more data would improve accuracy';
        }
        
        // Penalize for low clicks
        if ($clicks < 50) {
            $score -= 25;
            $notes[] = 'Limited click data affects CTR analysis reliability';
        } elseif ($clicks < 200) {
            $score -= 10;
            $notes[] = 'Click volume is acceptable but could be higher';
        }
        
        // Penalize for low conversions
        if ($conversions < 5) {
            $score -= 25;
            $notes[] = 'Insufficient conversion data for CPA/ROAS recommendations';
        } elseif ($conversions < 15) {
            $score -= 10;
            $notes[] = 'Limited conversion data - conversion-based recommendations less reliable';
        }
        
        return [
            'score' => max(0, $score),
            'notes' => $notes,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
        ];
    }

    /**
     * Enhance AI recommendations with calculated confidence scores.
     */
    protected function enhanceWithConfidenceScores(
        array $recommendations,
        array $metrics,
        ?array $historicalMetrics,
        array $dataQuality
    ): array {
        if (!isset($recommendations['recommendations'])) {
            return $recommendations;
        }

        foreach ($recommendations['recommendations'] as &$rec) {
            $confidence = $this->calculateConfidenceScore($rec, $metrics, $historicalMetrics, $dataQuality);
            $rec['confidence_score'] = $confidence['score'];
            $rec['confidence_factors'] = $confidence['factors'];
            $rec['auto_apply_eligible'] = $confidence['score'] >= $this->autoApplyThreshold;
            $rec['requires_review'] = $confidence['score'] < $this->reviewRequiredThreshold;
        }

        return $recommendations;
    }

    /**
     * Calculate confidence score for a specific recommendation.
     */
    protected function calculateConfidenceScore(
        array $recommendation,
        array $metrics,
        ?array $historicalMetrics,
        array $dataQuality
    ): array {
        $factors = [];
        $baseScore = 0.7; // Start with 70% base confidence
        
        $type = strtoupper($recommendation['type'] ?? '');
        $impact = strtoupper($recommendation['impact'] ?? 'MEDIUM');
        
        // Factor 1: Data Quality (30% weight)
        $dataQualityFactor = $dataQuality['score'] / 100;
        $factors['data_quality'] = round($dataQualityFactor, 2);
        
        // Factor 2: Recommendation Type Confidence (25% weight)
        $typeConfidence = $this->getTypeConfidence($type, $metrics);
        $factors['type_confidence'] = $typeConfidence;
        
        // Factor 3: Historical Consistency (25% weight)
        $historicalConfidence = $this->getHistoricalConfidence($recommendation, $historicalMetrics);
        $factors['historical_consistency'] = $historicalConfidence;
        
        // Factor 4: Impact Level Adjustment (20% weight)
        $impactFactor = match ($impact) {
            'HIGH' => 0.9, // High impact = higher confidence in significance
            'MEDIUM' => 0.7,
            'LOW' => 0.5,
            default => 0.6,
        };
        $factors['impact_significance'] = $impactFactor;
        
        // Calculate weighted score
        $score = (
            ($dataQualityFactor * 0.30) +
            ($typeConfidence * 0.25) +
            ($historicalConfidence * 0.25) +
            ($impactFactor * 0.20)
        );
        
        // Apply base score adjustment
        $finalScore = min(1.0, $baseScore * $score / 0.7);
        
        return [
            'score' => round($finalScore, 2),
            'factors' => $factors,
        ];
    }

    /**
     * Get confidence based on recommendation type and available data.
     */
    protected function getTypeConfidence(string $type, array $metrics): float
    {
        $impressions = $metrics['impressions'] ?? 0;
        $clicks = $metrics['clicks'] ?? 0;
        $conversions = $metrics['conversions'] ?? 0;
        
        return match ($type) {
            'BUDGET' => $impressions >= 1000 ? 0.85 : 0.5,
            'BIDDING' => $conversions >= $this->minConversionsForCpaAnalysis ? 0.8 : 0.4,
            'KEYWORDS' => $clicks >= $this->minClicksForCtrAnalysis ? 0.75 : 0.45,
            'AD_EXTENSIONS' => $impressions >= 1000 ? 0.85 : 0.5,
            'SCHEDULE' => $clicks >= 500 ? 0.75 : 0.4,
            'AUDIENCE' => $conversions >= 30 ? 0.8 : 0.3,
            'ADS' => $clicks >= 100 ? 0.7 : 0.4,
            'TARGETING' => $impressions >= 5000 ? 0.7 : 0.35,
            default => 0.5,
        };
    }

    /**
     * Get confidence based on historical data consistency.
     */
    protected function getHistoricalConfidence(array $recommendation, ?array $historicalMetrics): float
    {
        if (!$historicalMetrics) {
            return 0.5;
        }
        
        $type = $recommendation['type'] ?? '';
        $direction = $recommendation['direction'] ?? 'increase';

        // Check if the recommended direction aligns with historical trends
        $metricKey = strtolower($type) . '_trend';
        $historicalTrend = $historicalMetrics[$metricKey] ?? null;

        if ($historicalTrend === null) {
            return 0.6; // Slight confidence boost for having historical data
        }

        // Higher confidence if recommendation aligns with trend
        $trendsAlign = ($direction === 'increase' && $historicalTrend > 0)
            || ($direction === 'decrease' && $historicalTrend < 0);

        return $trendsAlign ? 0.85 : 0.5;
    }

    /**
     * Categorize recommendations by confidence level for action.
     */
    protected function categorizeRecommendations(array $recommendations): array
    {
        $categorized = [
            'auto_apply' => [],
            'recommended' => [],
            'review_required' => [],
        ];

        foreach ($recommendations['recommendations'] ?? [] as $rec) {
            $score = $rec['confidence_score'] ?? 0;
            
            if ($score >= $this->autoApplyThreshold) {
                $categorized['auto_apply'][] = $rec;
            } elseif ($score >= $this->reviewRequiredThreshold) {
                $categorized['recommended'][] = $rec;
            } else {
                $categorized['review_required'][] = $rec;
            }
        }

        return $categorized;
    }

    /**
     * Get Google Ads metrics for last 30 days.
     */
    protected function getGoogleMetrics(Campaign $campaign): ?array
    {
        try {
            $resourceName = "customers/{$campaign->customer->google_ads_customer_id}/campaigns/{$campaign->google_ads_campaign_id}";
            return ($this->getGoogleCampaignPerformance)(
                $campaign->customer->google_ads_customer_id,
                $resourceName
            );
        } catch (\Exception $e) {
            Log::error("Failed to get Google metrics: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get Google Ads historical metrics for trend analysis (60-30 days ago).
     */
    protected function getGoogleHistoricalMetrics(Campaign $campaign): ?array
    {
        try {
            $data = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
                ->whereBetween('date', [
                    now()->subDays(60)->toDateString(),
                    now()->subDays(30)->toDateString(),
                ])
                ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')
                ->first();

            if (!$data || ($data->impressions ?? 0) == 0) {
                return null;
            }

            return [
                'impressions' => (int) $data->impressions,
                'clicks' => (int) $data->clicks,
                'cost_micros' => (float) $data->cost * 1000000,
                'conversions' => (float) $data->conversions,
                'ctr' => $data->impressions > 0 ? $data->clicks / $data->impressions : 0,
                'average_cpc' => $data->clicks > 0 ? ($data->cost / $data->clicks) * 1000000 : 0,
                'cost_per_conversion' => $data->conversions > 0 ? ($data->cost / $data->conversions) * 1000000 : 0,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get Facebook Ads metrics for last 30 days.
     */
    protected function getFacebookMetrics(Campaign $campaign): ?array
    {
        try {
            $insightService = new InsightService($campaign->customer);
            $dateEnd = now()->format('Y-m-d');
            $dateStart = now()->subDays(30)->format('Y-m-d');
            
            $insights = $insightService->getCampaignInsights(
                $campaign->facebook_ads_campaign_id,
                $dateStart,
                $dateEnd
            );

            if (empty($insights) || !isset($insights['data'][0])) {
                return null;
            }

            $data = $insights['data'][0];

            // Normalize to match Google structure for the prompt
            return [
                'impressions' => (int) ($data['impressions'] ?? 0),
                'clicks' => (int) ($data['clicks'] ?? 0),
                'cost_micros' => (float) ($data['spend'] ?? 0) * 1000000,
                'conversions' => $this->sumActions($data['actions'] ?? [], ['purchase', 'lead', 'complete_registration']),
                'ctr' => isset($data['clicks']) && isset($data['impressions']) && $data['impressions'] > 0 
                    ? $data['clicks'] / $data['impressions'] 
                    : 0,
                'average_cpc' => (float) ($data['cpc'] ?? 0) * 1000000,
                'cost_per_conversion' => (float) ($data['cost_per_action_type'][0]['value'] ?? 0) * 1000000,
                'frequency' => (float) ($data['frequency'] ?? 0),
                'reach' => (int) ($data['reach'] ?? 0),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get Facebook metrics for campaign {$campaign->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get Facebook historical metrics for trend analysis (60-30 days ago), normalized.
     */
    protected function getFacebookHistoricalMetrics(Campaign $campaign): ?array
    {
        try {
            $insightService = new InsightService($campaign->customer);
            $dateEnd = now()->subDays(30)->format('Y-m-d');
            $dateStart = now()->subDays(60)->format('Y-m-d');
            
            $insights = $insightService->getCampaignInsights(
                $campaign->facebook_ads_campaign_id,
                $dateStart,
                $dateEnd
            );

            if (empty($insights) || !isset($insights[0])) {
                return null;
            }

            $data = $insights[0];

            return [
                'impressions' => (int) ($data['impressions'] ?? 0),
                'clicks' => (int) ($data['clicks'] ?? 0),
                'cost_micros' => (float) ($data['spend'] ?? 0) * 1000000,
                'conversions' => $this->sumActions($data['actions'] ?? [], ['purchase', 'lead', 'complete_registration']),
                'ctr' => isset($data['clicks'], $data['impressions']) && $data['impressions'] > 0
                    ? $data['clicks'] / $data['impressions']
                    : 0,
                'average_cpc' => (float) ($data['cpc'] ?? 0) * 1000000,
                'cost_per_conversion' => (float) ($data['cost_per_action_type'][0]['value'] ?? 0) * 1000000,
                'frequency' => (float) ($data['frequency'] ?? 0),
                'reach' => (int) ($data['reach'] ?? 0),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sum multiple action types from Facebook insights.
     */
    protected function sumActions(array $actions, array $actionTypes): int
    {
        $total = 0;
        foreach ($actions as $action) {
            if (in_array($action['action_type'], $actionTypes)) {
                $total += (int) $action['value'];
            }
        }
        return $total;
    }

    /**
     * Cache recommendations for retrieval.
     */
    protected function cacheRecommendations(int $campaignId, array $recommendations): void
    {
        Cache::put(
            "optimization:campaign:{$campaignId}",
            $recommendations,
            now()->addHours(12)
        );
    }

    /**
     * Get cached recommendations for a campaign.
     */
    public function getCachedRecommendations(int $campaignId): ?array
    {
        return Cache::get("optimization:campaign:{$campaignId}");
    }

    /**
     * Apply a specific recommendation (for auto-apply eligible ones).
     */
    public function applyRecommendation(Campaign $campaign, array $recommendation): array
    {
        $type = $recommendation['type'] ?? null;
        
        if (!$type) {
            return [
                'applied' => false,
                'message' => 'Recommendation type is missing',
                'recommendation' => $recommendation,
            ];
        }

        try {
            return match ($type) {
                'BUDGET' => $this->applyBudgetRecommendation($campaign, $recommendation),
                'KEYWORDS' => $this->applyKeywordRecommendation($campaign, $recommendation),
                'BIDDING' => $this->applyBiddingRecommendation($campaign, $recommendation),
                'TARGETING' => $this->applyTargetingRecommendation($campaign, $recommendation),
                'AD_EXTENSIONS' => $this->applyAdExtensionRecommendation($campaign, $recommendation),
                'SCHEDULE' => $this->applyScheduleRecommendation($campaign, $recommendation),
                'AUDIENCE' => $this->applyAudienceRecommendation($campaign, $recommendation),
                default => [
                    'applied' => false,
                    'message' => "Auto-apply not yet supported for recommendation type: {$type}",
                    'recommendation' => $recommendation,
                ],
            };
        } catch (\Exception $e) {
            Log::error("Failed to apply recommendation: " . $e->getMessage(), [
                'campaign_id' => $campaign->id,
                'recommendation' => $recommendation,
            ]);
            return [
                'applied' => false,
                'message' => 'Failed to apply: ' . $e->getMessage(),
                'recommendation' => $recommendation,
            ];
        }
    }

    /**
     * Apply a budget adjustment recommendation.
     */
    protected function applyBudgetRecommendation(Campaign $campaign, array $recommendation): array
    {
        $newBudget = $recommendation['suggested_value'] ?? null;

        if (!$newBudget || $newBudget <= 0) {
            return [
                'applied' => false,
                'message' => 'Invalid budget value in recommendation',
                'recommendation' => $recommendation,
            ];
        }

        $oldBudget = $campaign->daily_budget;
        $campaign->daily_budget = $newBudget;
        $campaign->save();

        // Also update Google Ads API if campaign is linked
        if ($campaign->google_ads_campaign_id && $campaign->customer) {
            try {
                $customer = $campaign->customer;
                $customerId = $customer->google_ads_customer_id;
                $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
                $updateBudget = new UpdateCampaignBudget($customer);
                ($updateBudget)($customerId, $campaignResourceName, (int) ($newBudget * 1_000_000));
            } catch (\Exception $e) {
                Log::warning("Applied budget locally but failed to update Google Ads API: " . $e->getMessage());
            }
        }

        Log::info("Applied budget recommendation for campaign {$campaign->id}", [
            'old_budget' => $oldBudget,
            'new_budget' => $newBudget,
        ]);

        return [
            'applied' => true,
            'message' => "Budget adjusted from {$oldBudget} to {$newBudget}",
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Apply a keyword recommendation (bid adjust, pause, or remove).
     */
    protected function applyKeywordRecommendation(Campaign $campaign, array $recommendation): array
    {
        $action = $recommendation['direction'] ?? $recommendation['action'] ?? null;
        $criterionResourceName = $recommendation['criterion_resource_name'] ?? null;
        $customer = $campaign->customer;

        if (!$customer || !$criterionResourceName) {
            return [
                'applied' => false,
                'message' => 'Missing customer or criterion resource name',
                'recommendation' => $recommendation,
            ];
        }

        $customerId = $customer->google_ads_customer_id;

        $result = match ($action) {
            'increase', 'decrease' => $this->adjustKeywordBid($customer, $customerId, $criterionResourceName, $recommendation),
            'pause' => $this->pauseKeyword($customer, $customerId, $criterionResourceName),
            'enable' => $this->enableKeyword($customer, $customerId, $criterionResourceName),
            'remove' => $this->removeKeyword($customer, $customerId, $criterionResourceName),
            default => ['applied' => false, 'message' => "Unknown keyword action: {$action}"],
        };

        $result['recommendation'] = $recommendation;
        return $result;
    }

    protected function adjustKeywordBid($customer, string $customerId, string $criterionResourceName, array $recommendation): array
    {
        $newBidMicros = $recommendation['suggested_value'] ?? null;
        if (!$newBidMicros) {
            return ['applied' => false, 'message' => 'No suggested bid value'];
        }

        $service = new UpdateKeywordBid($customer);
        $success = ($service)($customerId, $criterionResourceName, (int) $newBidMicros);

        return [
            'applied' => $success,
            'message' => $success
                ? "Keyword bid adjusted to {$newBidMicros} micros"
                : 'Failed to adjust keyword bid',
        ];
    }

    protected function pauseKeyword($customer, string $customerId, string $criterionResourceName): array
    {
        $service = new UpdateKeywordStatus($customer);
        $success = $service->pause($customerId, $criterionResourceName);

        return [
            'applied' => $success,
            'message' => $success ? 'Keyword paused' : 'Failed to pause keyword',
        ];
    }

    protected function enableKeyword($customer, string $customerId, string $criterionResourceName): array
    {
        $service = new UpdateKeywordStatus($customer);
        $success = $service->enable($customerId, $criterionResourceName);

        return [
            'applied' => $success,
            'message' => $success ? 'Keyword enabled' : 'Failed to enable keyword',
        ];
    }

    protected function removeKeyword($customer, string $customerId, string $criterionResourceName): array
    {
        $service = new RemoveKeyword($customer);
        $success = ($service)($customerId, $criterionResourceName);

        return [
            'applied' => $success,
            'message' => $success ? 'Keyword removed' : 'Failed to remove keyword',
        ];
    }

    /**
     * Apply a bidding recommendation (strategy-level changes).
     */
    protected function applyBiddingRecommendation(Campaign $campaign, array $recommendation): array
    {
        // Bidding strategy changes are high-risk — log for review
        Log::info("Bidding recommendation flagged for review", [
            'campaign_id' => $campaign->id,
            'recommendation' => $recommendation,
        ]);

        return [
            'applied' => false,
            'message' => 'Bidding strategy changes require manual review',
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Apply a targeting recommendation (device/location bid adjustments).
     */
    protected function applyTargetingRecommendation(Campaign $campaign, array $recommendation): array
    {
        $subType = $recommendation['sub_type'] ?? null;
        $customer = $campaign->customer;

        if (!$customer || !$campaign->google_ads_campaign_id) {
            return [
                'applied' => false,
                'message' => 'Missing customer or campaign Google ID',
                'recommendation' => $recommendation,
            ];
        }

        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";

        $result = match ($subType) {
            'device' => $this->applyDeviceBidAdjustment($customer, $customerId, $campaignResourceName, $recommendation),
            'location' => $this->applyLocationBidAdjustment($customer, $customerId, $campaignResourceName, $recommendation),
            default => ['applied' => false, 'message' => "Unknown targeting sub_type: {$subType}"],
        };

        $result['recommendation'] = $recommendation;
        return $result;
    }

    protected function applyDeviceBidAdjustment($customer, string $customerId, string $campaignResourceName, array $recommendation): array
    {
        $deviceType = $recommendation['device_type'] ?? null;
        $bidModifier = $recommendation['suggested_value'] ?? null;

        if (!$deviceType || $bidModifier === null) {
            return ['applied' => false, 'message' => 'Missing device type or bid modifier'];
        }

        $service = new SetDeviceBidAdjustment($customer);
        $resourceName = ($service)($customerId, $campaignResourceName, (int) $deviceType, (float) $bidModifier);

        return [
            'applied' => $resourceName !== null,
            'message' => $resourceName
                ? "Device bid adjustment set to {$bidModifier}x"
                : 'Failed to set device bid adjustment',
        ];
    }

    protected function applyLocationBidAdjustment($customer, string $customerId, string $campaignResourceName, array $recommendation): array
    {
        $geoTarget = $recommendation['geo_target_constant'] ?? null;
        $bidModifier = $recommendation['suggested_value'] ?? null;

        if (!$geoTarget || $bidModifier === null) {
            return ['applied' => false, 'message' => 'Missing geo target or bid modifier'];
        }

        $service = new SetLocationBidAdjustment($customer);
        $resourceName = ($service)($customerId, $campaignResourceName, $geoTarget, (float) $bidModifier);

        return [
            'applied' => $resourceName !== null,
            'message' => $resourceName
                ? "Location bid adjustment set to {$bidModifier}x for {$geoTarget}"
                : 'Failed to set location bid adjustment',
        ];
    }

    /**
     * Apply an ad schedule recommendation.
     */
    protected function applyScheduleRecommendation(Campaign $campaign, array $recommendation): array
    {
        $customer = $campaign->customer;

        if (!$customer || !$campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Missing customer or campaign Google ID', 'recommendation' => $recommendation];
        }

        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
        $service = new SetAdSchedule($customer);

        $scheduleType = $recommendation['sub_type'] ?? 'business_hours';

        if ($scheduleType === 'business_hours') {
            $bidModifier = (float) ($recommendation['suggested_value'] ?? 1.2);
            $results = $service->setBusinessHours($customerId, $campaignResourceName, $bidModifier);
            $applied = count(array_filter($results)) > 0;

            return [
                'applied' => $applied,
                'message' => $applied
                    ? 'Business hours schedule set (Mon-Fri 9am-5pm, ' . $bidModifier . 'x bid)'
                    : 'Failed to set business hours schedule',
                'recommendation' => $recommendation,
            ];
        }

        // Custom single-day schedule
        $dayOfWeek = (int) ($recommendation['day_of_week'] ?? 2);
        $startHour = (int) ($recommendation['start_hour'] ?? 9);
        $endHour = (int) ($recommendation['end_hour'] ?? 17);
        $bidModifier = (float) ($recommendation['suggested_value'] ?? 1.0);

        $resourceName = ($service)($customerId, $campaignResourceName, $dayOfWeek, $startHour, 2, $endHour, 2, $bidModifier);

        return [
            'applied' => $resourceName !== null,
            'message' => $resourceName
                ? "Ad schedule set for day {$dayOfWeek} ({$startHour}:00-{$endHour}:00, {$bidModifier}x bid)"
                : 'Failed to set ad schedule',
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Apply an ad extension recommendation.
     */
    protected function applyAdExtensionRecommendation(Campaign $campaign, array $recommendation): array
    {
        $customer = $campaign->customer;

        if (!$customer || !$campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Missing customer or campaign Google ID', 'recommendation' => $recommendation];
        }

        $customerId = $customer->google_ads_customer_id;
        $campaignResourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
        $extensionType = $recommendation['sub_type'] ?? null;

        if (!$extensionType) {
            return ['applied' => false, 'message' => 'Missing extension sub_type', 'recommendation' => $recommendation];
        }

        try {
            [$assetResource, $fieldType, $label] = match ($extensionType) {
                'structured_snippet' => $this->createStructuredSnippet($customer, $customerId, $recommendation),
                'call' => $this->createCallExtension($customer, $customerId, $recommendation),
                'price' => $this->createPriceExtension($customer, $customerId, $recommendation),
                'promotion' => $this->createPromotionExtension($customer, $customerId, $recommendation),
                default => [null, null, $extensionType],
            };

            if (!$assetResource) {
                return ['applied' => false, 'message' => "Failed to create {$extensionType} extension", 'recommendation' => $recommendation];
            }

            // Link to campaign
            $linker = new LinkCampaignAsset($customer);
            $linkResource = ($linker)($customerId, $campaignResourceName, $assetResource, $fieldType);

            return [
                'applied' => $linkResource !== null,
                'message' => $linkResource
                    ? "{$label} extension created and linked to campaign"
                    : "{$label} created but failed to link to campaign",
                'recommendation' => $recommendation,
            ];
        } catch (\Exception $e) {
            Log::error("Ad extension recommendation failed: " . $e->getMessage(), [
                'campaign_id' => $campaign->id,
                'extension_type' => $extensionType,
            ]);
            return ['applied' => false, 'message' => 'Error: ' . substr($e->getMessage(), 0, 200), 'recommendation' => $recommendation];
        }
    }

    protected function createStructuredSnippet($customer, string $customerId, array $rec): array
    {
        $header = $rec['header'] ?? 'Services';
        $values = $rec['values'] ?? $rec['items'] ?? [];

        if (empty($values)) {
            return [null, null, 'Structured Snippet'];
        }

        $service = new CreateStructuredSnippetAsset($customer);
        $resource = ($service)($customerId, $header, $values);
        return [$resource, AssetFieldType::STRUCTURED_SNIPPET, 'Structured Snippet'];
    }

    protected function createCallExtension($customer, string $customerId, array $rec): array
    {
        $phone = $rec['phone_number'] ?? null;
        $country = $rec['country_code'] ?? 'AU';

        if (!$phone) {
            return [null, null, 'Call'];
        }

        $service = new CreateCallAsset($customer);
        $resource = ($service)($customerId, $phone, $country);
        return [$resource, AssetFieldType::CALL, 'Call'];
    }

    protected function createPriceExtension($customer, string $customerId, array $rec): array
    {
        $type = (int) ($rec['price_type'] ?? 8); // SERVICES
        $qualifier = (int) ($rec['price_qualifier'] ?? 2); // FROM
        $offerings = $rec['offerings'] ?? [];

        if (empty($offerings)) {
            return [null, null, 'Price'];
        }

        $service = new CreatePriceAsset($customer);
        $resource = ($service)($customerId, $type, $qualifier, $offerings);
        return [$resource, AssetFieldType::PRICE, 'Price'];
    }

    protected function createPromotionExtension($customer, string $customerId, array $rec): array
    {
        $target = $rec['promotion_target'] ?? null;
        $data = $rec['promotion_data'] ?? [];

        if (!$target || empty($data)) {
            return [null, null, 'Promotion'];
        }

        $service = new CreatePromotionAsset($customer);
        $resource = ($service)($customerId, $target, $data);
        return [$resource, AssetFieldType::PROMOTION, 'Promotion'];
    }

    /**
     * Apply an audience recommendation (customer match or remarketing).
     * Only runs when the campaign has 30+ days of conversion data (gated by confidence score).
     */
    protected function applyAudienceRecommendation(Campaign $campaign, array $recommendation): array
    {
        $customer = $campaign->customer;

        if (!$customer || !$campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Missing customer or campaign Google ID', 'recommendation' => $recommendation];
        }

        // Verify the campaign has sufficient maturity (30+ days of data)
        $daysSinceCreation = $campaign->created_at?->diffInDays(now()) ?? 0;
        if ($daysSinceCreation < 30) {
            return [
                'applied' => false,
                'message' => "Campaign is only {$daysSinceCreation} days old. Audience creation requires 30+ days of data.",
                'recommendation' => $recommendation,
            ];
        }

        $customerId = $customer->google_ads_customer_id;
        $subType = $recommendation['sub_type'] ?? 'remarketing';
        $listName = $recommendation['audience_name'] ?? $recommendation['description'] ?? 'Auto-created audience';

        try {
            $resourceName = match ($subType) {
                'customer_match' => $this->createCustomerMatchAudience($customer, $customerId, $recommendation),
                'remarketing' => $this->createRemarketingAudienceFromRec($customer, $customerId, $recommendation),
                default => null,
            };

            if (!$resourceName) {
                return ['applied' => false, 'message' => "Failed to create {$subType} audience", 'recommendation' => $recommendation];
            }

            // Record in local database
            Audience::create([
                'customer_id' => $customer->id,
                'campaign_id' => $campaign->id,
                'name' => $listName,
                'platform' => 'google',
                'type' => $subType,
                'platform_resource_name' => $resourceName,
                'status' => 'active',
                'source_data' => [
                    'recommendation' => $recommendation,
                    'created_by' => 'optimization_agent',
                ],
            ]);

            return [
                'applied' => true,
                'message' => "{$subType} audience '{$listName}' created: {$resourceName}",
                'recommendation' => $recommendation,
            ];
        } catch (\Exception $e) {
            Log::error("Audience recommendation failed: " . $e->getMessage(), [
                'campaign_id' => $campaign->id,
                'sub_type' => $subType,
            ]);
            return ['applied' => false, 'message' => 'Error: ' . substr($e->getMessage(), 0, 200), 'recommendation' => $recommendation];
        }
    }

    protected function createCustomerMatchAudience($customer, string $customerId, array $rec): ?string
    {
        $listName = $rec['audience_name'] ?? 'Customer Match';
        $service = new CreateUserList($customer);
        return ($service)($customerId, $listName, $rec['description'] ?? '');
    }

    protected function createRemarketingAudienceFromRec($customer, string $customerId, array $rec): ?string
    {
        $listName = $rec['audience_name'] ?? 'Remarketing Audience';
        $urlContains = $rec['url_contains'] ?? '/';
        $days = (int) ($rec['membership_days'] ?? 90);

        $service = new CreateRemarketingAudience($customer);
        return ($service)($customerId, $listName, $urlContains, $days);
    }
}
