<?php

namespace App\Prompts;

class ProposalPrompt
{
    /**
     * System instruction for the proposal generation model.
     */
    public static function getSystemInstruction(): string
    {
        return <<<SYSTEM
You are an elite digital advertising strategist preparing a professional advertising proposal for a prospective client.
You write compelling, data-informed proposals that win business. Your tone is confident yet approachable, and you back every recommendation with reasoning.
Output valid JSON only — no markdown, no commentary outside the JSON structure.
SYSTEM;
    }

    /**
     * Build the full proposal generation prompt.
     */
    public static function build(
        string $clientName,
        string $industry,
        ?string $websiteContent,
        float $budget,
        ?string $goals,
        array $platforms,
        ?string $brandContext = null,
    ): string {
        $platformList = implode(', ', $platforms);

        $websiteSection = $websiteContent
            ? "## WEBSITE ANALYSIS\nThe following is content extracted from the client's website:\n\n{$websiteContent}\n\n"
            : '';

        $brandSection = $brandContext
            ? "## BRAND CONTEXT\n{$brandContext}\n\n"
            : '';

        $goalsSection = $goals
            ? "## CLIENT GOALS\n{$goals}\n\n"
            : '';

        return <<<PROMPT
Generate a comprehensive advertising proposal for the following client.

## CLIENT INFORMATION
- Company Name: {$clientName}
- Industry: {$industry}
- Monthly Ad Budget: \${$budget}
- Advertising Platforms: {$platformList}

{$goalsSection}{$websiteSection}{$brandSection}## REQUIRED OUTPUT (JSON)

Return a JSON object with the following structure:

{
  "executive_summary": "A compelling 3-4 paragraph executive summary tailored to the client's business, goals, and industry challenges.",
  "industry_analysis": "2-3 paragraphs analyzing the client's industry landscape, competitive environment, and advertising opportunities.",
  "platform_strategies": [
    {
      "platform": "Google Ads|Facebook & Instagram|etc.",
      "campaign_types": ["Search", "Performance Max", etc.],
      "strategy_overview": "2-3 paragraphs explaining the platform strategy.",
      "targeting_approach": "Description of audience targeting methodology.",
      "budget_allocation": 0.00,
      "budget_percentage": 0,
      "expected_metrics": {
        "estimated_impressions": "range string",
        "estimated_clicks": "range string",
        "estimated_ctr": "range string",
        "estimated_cpc": "range string",
        "estimated_conversions": "range string"
      },
      "sample_ad_concepts": [
        {
          "headline": "Sample headline",
          "description": "Sample description",
          "call_to_action": "CTA text"
        }
      ]
    }
  ],
  "timeline": [
    {
      "phase": "Phase name",
      "duration": "Week 1-2",
      "activities": ["Activity 1", "Activity 2"]
    }
  ],
  "projected_results": {
    "month_1": "Expected results in month 1",
    "month_3": "Expected results by month 3",
    "month_6": "Expected results by month 6"
  },
  "investment_summary": {
    "monthly_ad_spend": {$budget},
    "management_fee": "Suggested management fee or description",
    "total_monthly": "Total monthly investment"
  },
  "why_us": [
    "Differentiator 1 — why our AI-powered platform outperforms traditional agencies",
    "Differentiator 2",
    "Differentiator 3"
  ]
}

IMPORTANT GUIDELINES:
- Budget allocations across platforms must sum to the total monthly budget ({$budget}).
- All metrics should be realistic for the industry and budget.
- Sample ad concepts should be specific to the client's business based on website content.
- The executive summary should lead with the client's business challenges and how we solve them.
- Timeline should be realistic with 3-4 phases spanning 6 months.
- "why_us" should emphasize AI-driven optimization, real-time adjustments, and transparent reporting.
PROMPT;
    }
}
