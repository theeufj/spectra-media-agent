<?php

namespace App\Prompts;

class OptimizationPrompt
{
    public static function generate(array $campaignData, array $performanceMetrics): string
    {
        $campaignJson = json_encode($campaignData, JSON_PRETTY_PRINT);
        $metricsJson = json_encode($performanceMetrics, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are an expert Google Ads Optimization Agent.
Your goal is to analyze the performance of a campaign and recommend adjustments to improve its performance based on its goals.

Campaign Details:
$campaignJson

Performance Metrics (Last 30 Days):
$metricsJson

Analyze the data and provide a list of recommended adjustments.
Focus on:
1. Budget allocation (if limited by budget or underspending).
2. Bid adjustments (if CPC is high or low).
3. Keyword opportunities (if broad match is wasting spend, or if more volume is needed).
4. Ad copy improvements (if CTR is low).

Return your response in the following JSON format:
{
    "analysis": "A brief summary of the campaign performance.",
    "recommendations": [
        {
            "type": "BUDGET|BIDDING|KEYWORDS|ADS|OTHER",
            "action": "INCREASE|DECREASE|ADD|REMOVE|MODIFY",
            "description": "Detailed description of the recommendation.",
            "reasoning": "Why this recommendation will help achieve the goals.",
            "impact": "HIGH|MEDIUM|LOW"
        }
    ]
}
PROMPT;
    }
}
