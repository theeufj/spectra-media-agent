<?php

namespace App\Prompts;

class SeasonalStrategyPrompt
{
    private array $campaignData;
    private string $season;
    private array $baselineStrategy;

    public function __construct(array $campaignData, string $season, array $baselineStrategy)
    {
        $this->campaignData = $campaignData;
        $this->season = $season;
        $this->baselineStrategy = $baselineStrategy;
    }

    public function getPrompt(): string
    {
        $prompt = "You are an expert marketing strategist. Based on the current campaign data, the upcoming season ({$this->season}), and the baseline seasonal strategy, generate a comprehensive and nuanced strategy shift. The output should be a JSON object detailing the recommended changes.\n\n";
        $prompt .= "Current Campaign Data:\n" . json_encode($this->campaignData, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Baseline Strategy for {$this->season}:\n" . json_encode($this->baselineStrategy, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Analyze this information and generate a specific, actionable strategy shift. The JSON output should include keys like 'budget_adjustment', 'bidding_strategy_change', 'new_keywords', 'ad_copy_suggestions', and 'targeting_refinements'.\n\n";
        $prompt .= "Example of desired JSON output:\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "    \"budget_adjustment\": {\"new_daily_budget\": 150.00},\n";
        $prompt .= "    \"bidding_strategy_change\": {\"new_strategy\": \"TARGET_CPA\", \"target_cpa_micros\": 25000000},\n";
        $prompt .= "    \"new_keywords\": [\"Black Friday 2025\", \"best tech deals\"],\n";
        $prompt .= "    \"ad_copy_suggestions\": [\n";
        $prompt .= "        {\"headline\": \"Black Friday Doorbusters!\", \"description\": \"Save up to 70% this week only.\"},\n";
        $prompt .= "        {\"headline\": \"Cyber Monday Sneak Peek\", \"description\": \"Get early access to our biggest sale.\"}\n";
        $prompt .= "    ],\n";
        $prompt .= "    \"targeting_refinements\": {\"add_audiences\": [\"in-market/electronics\"], \"remove_placements\": [\"example.com/low-performing-site\"]}\n";
        $prompt .= "}\n";
        $prompt .= "```\n\n";
        $prompt .= "Provide only the JSON output.\n";

        return $prompt;
    }
}
