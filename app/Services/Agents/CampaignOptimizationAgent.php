<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Services\GeminiService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\FacebookAds\InsightService;
use App\Prompts\OptimizationPrompt;
use Illuminate\Support\Facades\Log;

class CampaignOptimizationAgent
{
    protected GeminiService $gemini;
    protected GetCampaignPerformance $getGoogleCampaignPerformance;

    public function __construct(GeminiService $gemini, GetCampaignPerformance $getGoogleCampaignPerformance)
    {
        $this->gemini = $gemini;
        $this->getGoogleCampaignPerformance = $getGoogleCampaignPerformance;
    }

    /**
     * Analyze a campaign and generate recommendations.
     *
     * @param Campaign $campaign
     * @return array|null
     */
    public function analyze(Campaign $campaign): ?array
    {
        $metrics = null;
        $platform = null;

        // Determine platform and fetch metrics
        if ($campaign->google_ads_campaign_id && $campaign->customer_id) {
            $platform = 'Google Ads';
            $metrics = $this->getGoogleMetrics($campaign);
        } elseif ($campaign->facebook_ads_campaign_id && $campaign->customer) {
            $platform = 'Facebook Ads';
            $metrics = $this->getFacebookMetrics($campaign);
        }

        if (!$metrics) {
            Log::info("No performance data found for campaign {$campaign->id} ({$platform}).");
            return null;
        }

        // 2. Prepare Data for AI
        $campaignData = [
            'name' => $campaign->name,
            'platform' => $platform,
            'goals' => $campaign->goals,
            'total_budget' => $campaign->total_budget,
            'daily_budget' => $campaign->daily_budget,
            'primary_kpi' => $campaign->primary_kpi,
            'product_focus' => $campaign->product_focus,
        ];

        // 3. Generate Prompt
        $prompt = OptimizationPrompt::generate($campaignData, $metrics);

        // 4. Call AI
        try {
            $response = $this->gemini->generateContent($prompt);
            
            // Extract JSON from response
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $json = $matches[0];
                return json_decode($json, true);
            }
            
            Log::error("Failed to parse AI response for campaign {$campaign->id}");
            return null;

        } catch (\Exception $e) {
            Log::error("Optimization Agent failed for campaign {$campaign->id}: " . $e->getMessage());
            return null;
        }
    }

    protected function getGoogleMetrics(Campaign $campaign): ?array
    {
        $resourceName = "customers/{$campaign->customer_id}/campaigns/{$campaign->google_ads_campaign_id}";
        return ($this->getGoogleCampaignPerformance)($campaign->customer_id, $resourceName);
    }

    protected function getFacebookMetrics(Campaign $campaign): ?array
    {
        try {
            $insightService = new InsightService($campaign->customer);
            // Get last 30 days
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
                'impressions' => $data['impressions'] ?? 0,
                'clicks' => $data['clicks'] ?? 0,
                'cost_micros' => ($data['spend'] ?? 0) * 1000000, // Convert to micros to match Google
                'conversions' => $this->sumActions($data['actions'] ?? [], 'purchase'), // Simplified conversion logic
                'ctr' => isset($data['clicks']) && isset($data['impressions']) && $data['impressions'] > 0 
                    ? $data['clicks'] / $data['impressions'] 
                    : 0,
                'average_cpc' => ($data['cpc'] ?? 0) * 1000000,
                'cost_per_conversion' => ($data['cpa'] ?? 0) * 1000000,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get Facebook metrics for campaign {$campaign->id}: " . $e->getMessage());
            return null;
        }
    }

    protected function sumActions(array $actions, string $actionType): int
    {
        foreach ($actions as $action) {
            if ($action['action_type'] === $actionType) {
                return (int) $action['value'];
            }
        }
        return 0;
    }
}
