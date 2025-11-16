<?php

namespace App\Prompts;

class GoogleAdsRecommendationPrompt
{
    private array $performanceData;
    private array $campaignConfig;

    public function __construct(array $performanceData, array $campaignConfig)
    {
        $this->performanceData = $performanceData;
        $this->campaignConfig = $campaignConfig;
    }

    public function getPrompt(): string
    {
        $prompt = "You are an expert Google Ads analyst. Based on the following performance data and campaign configuration, provide actionable recommendations to optimize the campaign. \n\n";
        $prompt .= "Current Campaign Configuration:\n" . json_encode($this->campaignConfig, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Recent Performance Data (last " . count($this->performanceData) . " days):\n" . json_encode($this->performanceData, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Analyze this data and provide recommendations in a JSON array format. Each recommendation should have 'type', 'target_entity' (e.g., campaignId, adGroupId), 'parameters' (e.g., new_budget_amount, new_bidding_strategy), and 'rationale'. Focus on areas like budget adjustments, bidding strategy changes, ad copy optimization ideas, or targeting refinements. If no specific recommendation is identified, return an empty array.\n\n";
        $prompt .= "Example of desired JSON output:\n";
        $prompt .= "```json\n";
        $prompt .= "[\n";
        $prompt .= "    {\n";
        $prompt .= "        \"type\": \"BUDGET_INCREASE\",\n";
        $prompt .= "        \"target_entity\": {\"campaignId\": \"12345\"},\n";
        $prompt .= "        \"parameters\": {\"new_budget_amount\": 100.00},\n";
        $prompt .= "        \"rationale\": \"Campaign is consistently hitting daily budget cap and performing well.\"\n";
        $prompt .= "    },\n";
        $prompt .= "    {\n";
        $prompt .= "        \"type\": \"AD_COPY_OPTIMIZATION\",\n";
        $prompt .= "        \"target_entity\": {\"adGroupId\": \"67890\"},\n";
        $prompt .= "        \"parameters\": {\"headline_suggestion\": \"Achieve More Today\"},\n";
        $prompt .= "        \"rationale\": \"Low CTR on existing headlines indicates a need for new creative.\"\n";
        $prompt .= "    }\n";
        $prompt .= "]\n";
        $prompt .= "```\n\n";
        $prompt .= "Provide only the JSON output, without any additional text or markdown fences outside the JSON block.\n";

        return $prompt;
    }
}
