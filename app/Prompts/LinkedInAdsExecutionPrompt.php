<?php

namespace App\Prompts;

use App\Services\Agents\ExecutionContext;

class LinkedInAdsExecutionPrompt
{
    public static function getSystemInstruction(): string
    {
        return <<<INSTRUCTION
You are an expert LinkedIn Ads campaign strategist and B2B marketer with deep knowledge of the LinkedIn Ads API.

Your expertise includes:
- B2B targeting (Job Titles, Seniority, Company Size, Industry, Member Traits)
- Sponsored Content (Single Image, Carousel, Document) and Message Ads/InMail
- Lead Gen Forms setup and optimization
- B2B budgeting, bidding (Maximum Delivery vs Manual), and pacing
- Insight Tag configuration and conversion tracking

Use your reasoning to create execution plans for deploying campaigns on LinkedIn Ads. You must return an ExecutionPlan structured as JSON.
INSTRUCTION;
    }
    
    public static function generate(ExecutionContext $context): string
    {
        $campaign = $context->campaign;
        $strategy = $context->strategy;
        
        $dailyBudget = $context->calculateDailyBudget();
        
        return <<<PROMPT
Generate a comprehensive LinkedIn Ads execution plan focusing on B2B targeting. Return ONLY valid JSON structured for the ExecutionPlan format. Do not use Markdown formatting for the JSON.

# CAMPAIGN INFORMATION
**Campaign Name:** {$campaign->name}
**Campaign Description:** {$campaign->description}
**Industry/Target Vertical:** {$campaign->industry}

**Total Campaign Budget:** \${$campaign->total_budget}
**Recommended Daily Budget:** \${$dailyBudget}

# EXECUTION PLAN FORMAT
You must respond with a JSON object that matches this structure EXACTLY:
{
    "steps": [
        {
            "action": "string (e.g., 'create_campaign', 'set_targeting', 'create_creatives', 'setup_conversion_tracking')",
            "description": "string",
            "params": {
                // key-value pairs needed for this step such as 'job_titles', 'company_sizes'
            }
        }
    ],
    "reasoning": "string explaining your B2B strategy and why the selected targeting was chosen",
    "estimatedDuration": "string"
}
PROMPT;
    }
}
