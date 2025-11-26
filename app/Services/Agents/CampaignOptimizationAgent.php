<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\FacebookAds\InsightService;
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
                model: 'gemini-2.5-pro',
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
            return 0.5; // Neutral if no historical data
        }
        
        // Compare current trends with historical patterns
        // If recommendation aligns with historical improvement patterns, increase confidence
        // This is a simplified implementation - would need more sophisticated analysis
        
        return 0.7; // Default moderate confidence when historical data exists
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
     * Get Google Ads historical metrics for trend analysis.
     */
    protected function getGoogleHistoricalMetrics(Campaign $campaign): ?array
    {
        try {
            // Get metrics from 60-30 days ago for comparison
            $resourceName = "customers/{$campaign->customer->google_ads_customer_id}/campaigns/{$campaign->google_ads_campaign_id}";
            return ($this->getGoogleCampaignPerformance)(
                $campaign->customer->google_ads_customer_id,
                $resourceName,
                'LAST_30_DAYS' // Would ideally use a custom date range
            );
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
     * Get Facebook historical metrics for trend analysis.
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

            if (empty($insights) || !isset($insights['data'][0])) {
                return null;
            }

            return $insights['data'][0];
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
        // This would implement actual application of recommendations
        // For now, return a status indicating the action would be taken
        return [
            'applied' => false,
            'message' => 'Auto-apply functionality requires implementation of specific action handlers',
            'recommendation' => $recommendation,
        ];
    }
}
