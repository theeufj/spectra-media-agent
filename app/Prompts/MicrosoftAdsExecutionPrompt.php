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
        
        $dailyBudget = $context->calculateDailyBudget();
        // Microsoft Ads typically uses a lower budget slice, but we will plan to deploy based on the daily budget calculation
        
        return <<<PROMPT
Generate a comprehensive Microsoft Ads execution plan for the following campaign. Return ONLY valid JSON structured for the ExecutionPlan format. Do not use Markdown formatting for the JSON.

# CAMPAIGN INFORMATION
**Campaign Name:** {$campaign->name}
**Campaign Description:** {$campaign->description}
**Landing Page:** {$campaign->landing_page_url}

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
