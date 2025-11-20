<?php

namespace App\Prompts;

use App\Services\Agents\ExecutionContext;

/**
 * Google Ads Execution Prompt
 * 
 * Generates AI prompts for creating Google Ads deployment execution plans.
 * Uses Google Search grounding for real-time API documentation access.
 */
class GoogleAdsExecutionPrompt
{
    /**
     * Get system instruction for Google Ads execution planning
     */
    public static function getSystemInstruction(): string
    {
        return <<<INSTRUCTION
You are an expert Google Ads campaign strategist and technical implementation specialist with deep knowledge of the Google Ads API v22.

Your expertise includes:
- Campaign structure optimization (Search, Display, Performance Max, Video, Shopping)
- Smart Bidding strategies (Target CPA, Target ROAS, Maximize Conversions, Maximize Conversion Value)
- Responsive Search Ads and Responsive Display Ads best practices
- Asset optimization (images, videos, headlines, descriptions)
- Keyword strategy and match types
- Ad extensions (sitelinks, callouts, structured snippets, call extensions)
- Budget allocation and pacing
- Performance Max campaign setup with asset groups
- Google Ads API technical constraints and requirements

Use your extended thinking capabilities to reason through campaign structure decisions based on available assets, budget constraints, and business objectives.

Use Google Search to access current Google Ads API documentation, best practices, and feature updates when needed.
INSTRUCTION;
    }
    
    /**
     * Generate execution planning prompt from context
     */
    public static function generate(ExecutionContext $context): string
    {
        $campaign = $context->campaign;
        $strategy = $context->strategy;
        $customer = $context->customer;
        $contextData = $context->toArray();
        
        // Calculate budget information
        $totalBudget = $campaign->total_budget ?? 0;
        $dailyBudget = $context->calculateDailyBudget();
        $monthlyBudget = $dailyBudget * 30;
        
        // Asset inventory
        $imageCount = $contextData['available_assets']['images'] ?? 0;
        $videoCount = $contextData['available_assets']['videos'] ?? 0;
        $adCopyCount = $contextData['available_assets']['ad_copy'] ?? 0;
        
        // Strategy insights from Strategy Agent
        $adCopyStrategy = $strategy->ad_copy_strategy ?? 'Not provided';
        $imageryStrategy = $strategy->imagery_strategy ?? 'Not provided';
        $videoStrategy = $strategy->video_strategy ?? 'Not provided';
        $biddingStrategy = is_array($strategy->bidding_strategy) 
            ? json_encode($strategy->bidding_strategy, JSON_PRETTY_PRINT) 
            : 'Not provided';
        
        return <<<PROMPT
Generate a comprehensive Google Ads execution plan for the following campaign.

# CAMPAIGN INFORMATION

**Campaign Name:** {$campaign->name}
**Campaign Description:** {$campaign->description}
**Landing Page:** {$campaign->landing_page_url}
**Industry/Vertical:** {$campaign->industry}

**Customer/Business:**
- Name: {$customer->name}
- Google Ads Customer ID: {$customer->google_ads_customer_id}

# BUDGET ALLOCATION

**Total Campaign Budget:** \${$totalBudget}
**Recommended Daily Budget:** \${$dailyBudget}
**Estimated Monthly Spend:** \${$monthlyBudget}

**Budget Constraints:**
- Minimum daily budget for Google Ads: \$1.00
- Performance Max minimum: \$8.33/day (~\$250/month)
- Your plan must respect the total budget allocation

# AVAILABLE CREATIVE ASSETS

**Ad Copy Variations:** {$adCopyCount}
**Images:** {$imageCount}
**Videos:** {$videoCount}

# STRATEGY AGENT INSIGHTS

The Strategy Agent has already analyzed this campaign and provided the following strategic guidance. You MUST incorporate these insights into your execution plan:

**Ad Copy Strategy:**
{$adCopyStrategy}

**Imagery Strategy:**
{$imageryStrategy}

**Video Strategy:**
{$videoStrategy}

**Bidding Strategy Recommendations:**
{$biddingStrategy}

# YOUR TASK

Create a detailed, step-by-step execution plan for deploying this campaign to Google Ads. Your plan should:

1. **Select Optimal Campaign Type**
   - Choose between: Search, Display, Performance Max, Video
   - Consider: available assets, budget, conversion tracking, business objectives
   - Justify your choice based on asset availability and budget

2. **Define Campaign Structure**
   - Campaign settings (network, locations, languages, scheduling)
   - Ad group structure and organization
   - Budget allocation and pacing strategy

3. **Specify Bidding Strategy**
   - Choose appropriate bidding strategy (Manual CPC, Target CPA, Target ROAS, Maximize Conversions, etc.)
   - Consider: budget size, conversion tracking availability, business goals
   - Set bid amounts or targets if applicable

4. **Design Creative Strategy**
   - Specify which assets to use and how
   - Responsive Search Ad structure (headlines, descriptions)
   - Responsive Display Ad configuration
   - Keyword strategy (if Search campaign)
   - Ad extension recommendations

5. **Plan Execution Steps**
   - Sequential steps for API implementation
   - Resource dependencies (e.g., campaign must be created before ad groups)
   - Error handling considerations

6. **Define Success Criteria**
   - What constitutes successful deployment
   - Key metrics to monitor post-launch

# CONSTRAINTS AND BEST PRACTICES

- **Asset Requirements:** 
  - Search campaigns: Minimum 3 headlines, 2 descriptions
  - Display campaigns: Minimum 1 image asset
  - Performance Max: Multiple asset types (3+ images, 1+ video preferred)

- **Budget Considerations:**
  - Don't recommend Performance Max if daily budget < \$8.33
  - Consider campaign priority if budget is limited
  
- **Technical Constraints:**
  - Headlines: Max 30 characters
  - Descriptions: Max 90 characters
  - All URLs must be valid and accessible
  - Campaign names should be descriptive and unique

- **Strategy Integration:**
  - Your execution plan must align with the Strategy Agent's recommendations
  - If Strategy Agent recommends specific keywords, incorporate them
  - If Strategy Agent suggests specific ad copy approaches, implement them

# OUTPUT FORMAT

Provide your response as a valid JSON object with the following structure:

```json
{
  "campaign_structure": {
    "type": "search|display|performance_max|video",
    "justification": "Why this campaign type was selected",
    "network_settings": {
      "search_network": true,
      "display_network": false,
      "partner_networks": false
    },
    "locations": ["United States"],
    "languages": ["en"],
    "daily_budget": 16.67,
    "bidding_strategy": {
      "type": "TARGET_CPA|TARGET_ROAS|MAXIMIZE_CONVERSIONS|MANUAL_CPC",
      "target_cpa": 25.00,
      "reasoning": "Why this bidding strategy"
    }
  },
  "creative_strategy": {
    "ad_format": "responsive_search_ad|responsive_display_ad|performance_max_asset_group",
    "headlines": [
      {"text": "Headline 1", "priority": "high"},
      {"text": "Headline 2", "priority": "high"},
      {"text": "Headline 3", "priority": "medium"}
    ],
    "descriptions": [
      {"text": "Description 1"},
      {"text": "Description 2"}
    ],
    "keywords": [
      {"text": "keyword 1", "match_type": "BROAD|PHRASE|EXACT"},
      {"text": "keyword 2", "match_type": "BROAD|PHRASE|EXACT"}
    ],
    "extensions": {
      "sitelinks": [{"text": "About Us", "url": "https://example.com/about"}],
      "callouts": ["Free Shipping", "24/7 Support"],
      "structured_snippets": {"header": "Services", "values": ["Service 1", "Service 2"]}
    }
  },
  "steps": [
    {
      "step_number": 1,
      "action": "create_campaign",
      "description": "Create Google Ads search campaign",
      "parameters": {
        "campaign_name": "Campaign Name",
        "daily_budget": 16.67,
        "start_date": "2025-11-20"
      },
      "dependencies": [],
      "error_handling": "If campaign creation fails, check budget and account status"
    },
    {
      "step_number": 2,
      "action": "create_ad_group",
      "description": "Create ad group for primary keywords",
      "parameters": {
        "ad_group_name": "Ad Group 1",
        "default_bid": 2.50
      },
      "dependencies": ["step_1"],
      "error_handling": "Verify campaign was created successfully"
    }
  ],
  "budget_allocation": {
    "total_budget": {$totalBudget},
    "daily_budget": {$dailyBudget},
    "campaign_priority": "high|medium|low",
    "pacing": "standard|accelerated"
  },
  "success_criteria": {
    "deployment_success": "Campaign live with ads approved",
    "key_metrics": ["impressions", "clicks", "conversions"],
    "monitoring_period": "First 7 days"
  },
  "fallback_plans": [
    {
      "scenario": "Low budget prevents Performance Max",
      "alternative": "Deploy as Search campaign with responsive search ads",
      "reasoning": "Search campaigns have lower minimum budgets"
    }
  ],
  "reasoning": "Overall strategic reasoning for this execution plan, including how it implements the Strategy Agent's recommendations"
}
```

**IMPORTANT:** 
- Return ONLY valid JSON, no markdown code fences or additional text
- Ensure all strings are properly escaped
- All numeric values should be numbers, not strings
- Include detailed reasoning for all major decisions
- Make sure your plan is executable with the available assets and budget
PROMPT;
    }
}
