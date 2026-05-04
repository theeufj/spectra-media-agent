<?php

namespace App\Prompts;

use App\Services\Agents\ExecutionContext;

class MicrosoftAdsExecutionPrompt
{
    public static function getSystemInstruction(): string
    {
        return <<<INSTRUCTION
You are an expert Microsoft Ads (Bing Ads) campaign strategist and technical implementation specialist with deep knowledge of the Microsoft Advertising API.

Your expertise includes:
- Search campaign structure optimization
- Budget management and scheduling
- Keyword match types and negative keywords
- Text ads and Responsive Search Ads (RSAs)
- Sitelinks and Callout extensions
- Audience targeting, specifically LinkedIn profile targeting on Microsoft Ads
- UET (Universal Event Tracking) setup

Use your extended reasoning to create execution plans for deploying campaigns on Microsoft Ads. You must return an ExecutionPlan structured as JSON.
INSTRUCTION;
    }
    
    public static function generate(ExecutionContext $context): string
    {
        $campaign = $context->campaign;
        $strategy = $context->strategy;
        $customer = $context->customer;

        $dailyBudget    = $context->calculateDailyBudget();
        $landingPageUrl = $campaign->landing_page_url
            ?? $strategy->bidding_strategy['landing_page_url']
            ?? $customer->website
            ?? 'Not provided';

        return <<<PROMPT
Generate a comprehensive Microsoft Ads execution plan for the following campaign. Return ONLY valid JSON structured for the ExecutionPlan format. Do not use Markdown formatting for the JSON.

**BUYER PERSPECTIVE RULE:** Keywords and ad copy must reflect what a buyer searches when ready to pay for this service — outcomes and categories ("ppc agency", "managed google ads"), not product features or technology ("automation tool", "AI software").

# CAMPAIGN INFORMATION
**Campaign Name:** {$campaign->name}
**Campaign Description:** {$campaign->description}
**Landing Page:** {$landingPageUrl}

**Total Campaign Budget:** \${$campaign->total_budget}
**Recommended Daily Budget:** \${$dailyBudget}

# EXECUTION PLAN FORMAT
You must respond with a JSON object that matches this structure EXACTLY:
{
    "steps": [
        {
            "action": "string (e.g., 'create_campaign', 'import_from_google', 'create_ad_groups', 'configure_tracking', 'create_extensions')",
            "description": "string",
            "params": {
                // key-value pairs needed for this step
            }
        }
    ],
    "reasoning": "string explaining your strategy",
    "estimatedDuration": "string (e.g., '10 minutes')"
}
PROMPT;
    }
}
