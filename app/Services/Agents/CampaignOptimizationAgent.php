<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Prompts\OptimizationPrompt;
use App\Services\Agents\Optimization\MetricsFetcher;
use App\Services\Agents\Optimization\RecommendationApplier;
use App\Services\Agents\Optimization\RecommendationScorer;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates AI-powered campaign optimisation: fetches metrics, scores
 * AI recommendations for confidence, and applies high-confidence ones.
 *
 * Heavy lifting is delegated to:
 *   MetricsFetcher       — cross-platform metric retrieval
 *   RecommendationScorer — data quality + confidence scoring
 *   RecommendationApplier — applies individual recommendations to APIs
 */
class CampaignOptimizationAgent
{
    public function __construct(
        private GeminiService $gemini,
        private MetricsFetcher $fetcher,
        private RecommendationScorer $scorer,
        private RecommendationApplier $applier,
    ) {}

    public function analyze(Campaign $campaign): ?array
    {
        $metrics   = $this->fetcher->fetchCurrent($campaign);
        $platform  = $this->fetcher->platform($campaign);
        $historical = $this->fetcher->fetchHistorical($campaign);

        if (!$metrics) {
            Log::info("CampaignOptimizationAgent: No performance data for campaign {$campaign->id} ({$platform}).");
            return null;
        }

        $dataQuality  = $this->scorer->assessDataQuality($metrics);
        $campaignData = [
            'name'              => $campaign->name,
            'platform'          => $platform,
            'goals'             => $campaign->goals,
            'total_budget'      => $campaign->total_budget,
            'daily_budget'      => $campaign->daily_budget,
            'primary_kpi'       => $campaign->primary_kpi,
            'product_focus'     => $campaign->product_focus,
            'data_quality_score' => $dataQuality['score'],
            'data_quality_notes' => $dataQuality['notes'],
        ];

        $prompt = OptimizationPrompt::generate($campaignData, $metrics, $historical);

        try {
            $response = $this->gemini->generateContent(
                model: config('ai.models.default'),
                prompt: $prompt,
                config: ['temperature' => 0.7, 'maxOutputTokens' => 4096]
            );

            if (!$response || !isset($response['text'])) {
                Log::error("CampaignOptimizationAgent: Empty AI response for campaign {$campaign->id}");
                return null;
            }

            if (preg_match('/\{.*\}/s', $response['text'], $matches)) {
                $recommendations = json_decode($matches[0], true);

                if ($recommendations) {
                    $recommendations = $this->scorer->enhance($recommendations, $metrics, $historical, $dataQuality);
                    $recommendations['categorized'] = $this->scorer->categorize($recommendations);

                    Cache::put("optimization:campaign:{$campaign->id}", $recommendations, now()->addHours(12));

                    return $recommendations;
                }
            }

            Log::error("CampaignOptimizationAgent: Failed to parse AI response for campaign {$campaign->id}");
            return null;

        } catch (\Exception $e) {
            Log::error("CampaignOptimizationAgent: Failed for campaign {$campaign->id}: " . $e->getMessage());
            return null;
        }
    }

    public function getCachedRecommendations(int $campaignId): ?array
    {
        return Cache::get("optimization:campaign:{$campaignId}");
    }

    public function applyRecommendation(Campaign $campaign, array $recommendation): array
    {
        return $this->applier->apply($campaign, $recommendation);
    }
}
