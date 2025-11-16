<?php

namespace App\Services\GoogleAds;

use Illuminate\Support\Facades\Log;
use App\Services\GeminiService;
use App\Models\Recommendation;
use App\Prompts\GoogleAdsRecommendationPrompt;

class RecommendationGenerationService
{
    /**
     * Analyzes Google Ads performance data and generates actionable recommendations.
     *
     * @param array $performanceData The performance data fetched from Google Ads.
     * @param array $campaignConfig The current configuration of the campaign.
     * @return array An array of structured recommendations.
     */
    public function __invoke(array $performanceData, array $campaignConfig): array
    {
        $recommendations = [];

        Log::info("Analyzing performance data for recommendations", [
            'performance_data_count' => count($performanceData),
            'campaign_config' => $campaignConfig,
        ]);

        // Example: Simple budget increase recommendation if campaign is performing well and hitting budget
        if (!empty($performanceData) && isset($campaignConfig['dailyBudget'])) {
            $totalCost = array_sum(array_column($performanceData, 'cost'));
            $totalConversions = array_sum(array_column($performanceData, 'conversions'));
            $dailyBudget = $campaignConfig['dailyBudget'];
            $days = count($performanceData); // Number of days the data covers

            // Calculate average daily cost and conversions
            $averageDailyCost = $totalCost / $days;
            $averageDailyConversions = $totalConversions / $days;

            // Heuristic: If average daily cost is close to daily budget and conversions are good
            if ($averageDailyCost >= ($dailyBudget * 0.9) && $averageDailyConversions > 0) {
                $newBudgetAmount = $dailyBudget * 1.1; // Increase by 10%
                $recommendations[] = [
                    'type' => 'BUDGET_INCREASE',
                    'target_campaign_id' => $campaignConfig['campaignId'],
                    'new_budget_amount' => round($newBudgetAmount, 2),
                    'rationale' => "Campaign is consistently hitting daily budget cap and performing well with an average of " . round($averageDailyConversions, 2) . " conversions per day. Recommend increasing daily budget to " . round($newBudgetAmount, 2) . ".",
                ];
                Log::info("Generated budget increase recommendation", ['recommendation' => end($recommendations)]);
            }
        }

        // Integrate with LLMs for more nuanced recommendations
        $geminiService = new GeminiService();

        $googleAdsRecommendationPrompt = new GoogleAdsRecommendationPrompt($performanceData, $campaignConfig);
        $prompt = $googleAdsRecommendationPrompt->getPrompt();

        try {
            $generatedResponse = $geminiService->generateContent('gemini-2.5-pro', $prompt);

            if (is_null($generatedResponse) || !isset($generatedResponse['text'])) {
                Log::error("LLM failed to generate recommendations: Generated text was null or missing.", [
                    'performance_data_count' => count($performanceData),
                    'campaign_config' => $campaignConfig,
                ]);
            } else {
                $llmRecommendations = $this->parseLlmResponse($generatedResponse['text']);
                if (!empty($llmRecommendations)) {
                    $recommendations = array_merge($recommendations, $llmRecommendations);
                    Log::info("LLM generated additional recommendations.", [
                        'llm_recommendations_count' => count($llmRecommendations),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Error calling Gemini Service for recommendations: " . $e->getMessage(), [
                'exception' => $e,
                'performance_data_count' => count($performanceData),
                'campaign_config' => $campaignConfig,
            ]);
        }

        return $recommendations;
    }

    /**
     * Parses the LLM's JSON response into an array of recommendations.
     *
     * @param string $llmResponseText
     * @return array
     */
    private function parseLlmResponse(string $llmResponseText): array
    {
        $recommendations = [];
        try {
            // Remove markdown fences if present
            $cleanedJson = preg_replace('/^```json\\s*|\\s*```$/', '', trim($llmResponseText));
            $parsed = json_decode($cleanedJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("JSON decode error in LLM response: " . json_last_error_msg(), [
                    'llm_response_text' => $llmResponseText,
                ]);
                return [];
            }

            if (is_array($parsed)) {
                $recommendations = $parsed;
            } else {
                Log::warning("LLM response was not a valid JSON array.", [
                    'llm_response_text' => $llmResponseText,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error parsing LLM response: " . $e->getMessage(), [
                'exception' => $e,
                'llm_response_text' => $llmResponseText,
            ]);
        }
        return $recommendations;
    }
}
