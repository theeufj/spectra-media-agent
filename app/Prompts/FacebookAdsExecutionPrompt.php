<?php

namespace App\Prompts;

use App\Services\Agents\ExecutionContext;

/**
 * Facebook Ads Execution Prompt
 * 
 * Generates AI prompts for creating Facebook/Meta Ads deployment execution plans.
 * Uses Google Search grounding for real-time API documentation access.
 */
class FacebookAdsExecutionPrompt
{
    /**
     * Get system instruction for Facebook Ads execution planning
     */
    public static function getSystemInstruction(): string
    {
        return <<<INSTRUCTION
You are an expert Facebook/Meta Ads campaign strategist and technical implementation specialist with deep knowledge of the Facebook Marketing API.

Your expertise includes:
- Campaign objective selection (LINK_CLICKS, CONVERSIONS, REACH, TRAFFIC, ENGAGEMENT, APP_INSTALLS, etc.)
- Ad set structure and targeting optimization
- Creative format optimization (Single Image, Carousel, Video, Collection, Stories)
- Dynamic Creative optimization (DCO)
- Advantage+ Campaign setup and best practices
- Audience targeting (Core Audiences, Custom Audiences, Lookalike Audiences)
- Placement optimization across Facebook, Instagram, Messenger, and Audience Network
- Budget allocation and bid strategy (Lowest Cost, Cost Cap, Bid Cap)
- Facebook Pixel implementation and conversion tracking
- Creative best practices and policy compliance
- A/B testing strategies

Use your extended thinking capabilities to reason through campaign structure decisions based on available assets, budget constraints, business objectives, and audience targeting.

Use Google Search to access current Facebook Marketing API documentation, best practices, policy updates, and feature releases when needed.
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
        
        // Customer platform info
        $hasPixel = $customer->facebook_pixel_id ? 'Yes' : 'No';
        
        return <<<PROMPT
Generate a comprehensive Facebook/Meta Ads execution plan for the following campaign.

# CAMPAIGN INFORMATION

**Campaign Name:** {$campaign->name}
**Campaign Description:** {$campaign->description}
**Landing Page:** {$campaign->landing_page_url}
**Industry/Vertical:** {$campaign->industry}

**Customer/Business:**
- Name: {$customer->name}
- Facebook Ad Account ID: {$customer->facebook_ads_account_id}
- Facebook Page ID: {$customer->facebook_page_id}
- Facebook Pixel Installed: {$hasPixel}

# BUDGET ALLOCATION

**Total Campaign Budget:** \${$totalBudget}
**Recommended Daily Budget:** \${$dailyBudget}
**Estimated Monthly Spend:** \${$monthlyBudget}

**Budget Constraints:**
- Minimum daily budget per ad set: \$5.00
- Advantage+ Campaign minimum: \$50.00/day
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

Create a detailed, step-by-step execution plan for deploying this campaign to Facebook/Meta Ads. Your plan should:

1. **Select Optimal Campaign Objective**
   - Choose from: LINK_CLICKS, CONVERSIONS, REACH, TRAFFIC, ENGAGEMENT, VIDEO_VIEWS, LEAD_GENERATION, etc.
   - Consider: business goals, available tracking (Pixel), budget, and available creatives
   - Justify your choice based on campaign objectives and available infrastructure

2. **Define Campaign Structure**
   - Campaign settings (objective, special ad categories if applicable)
   - Ad set structure and organization
   - Budget allocation and pacing strategy
   - Optimization goal (matches objective)

3. **Design Targeting Strategy**
   - Geographic targeting (countries, regions, cities)
   - Demographic targeting (age, gender)
   - Interest and behavior targeting
   - Custom audiences (if Pixel available)
   - Lookalike audiences (if appropriate)
   - Audience size estimation

4. **Specify Placement Strategy**
   - Automatic placements vs manual selection
   - Facebook Feed, Instagram Feed, Stories, Reels
   - Messenger, Audience Network considerations
   - Device targeting (mobile, desktop)

5. **Design Creative Strategy**
   - Creative format: Single Image, Carousel, Video, Collection, Stories
   - Dynamic Creative optimization (if 3+ images and headlines available)
   - Primary text, headline, description
   - Call-to-action button
   - Link and display URL

6. **Plan Execution Steps**
   - Sequential steps for API implementation
   - Resource dependencies (e.g., campaign → ad set → creative → ad)
   - Error handling considerations

7. **Define Success Criteria**
   - What constitutes successful deployment
   - Key metrics to monitor post-launch
   - Optimization recommendations for first 7 days

# CONSTRAINTS AND BEST PRACTICES

- **Creative Requirements:**
  - Single Image: 1:1 ratio recommended (1080x1080px)
  - Carousel: 2-10 cards, 1:1 ratio per card
  - Video: 4:5 ratio for Feed, 9:16 for Stories
  - Primary text: Up to 125 characters (40 characters appear before "see more")
  - Headline: Up to 40 characters
  - Description: Up to 30 characters

- **Budget Considerations:**
  - Don't recommend Advantage+ if daily budget < \$50
  - Consider ad set budget vs campaign budget optimization
  - Minimum \$5/day per ad set
  
- **Targeting Considerations:**
  - Audience size should be at least 50,000 for delivery
  - Broader audiences often perform better with Meta's algorithm
  - Use detailed targeting expansion for better reach
  
- **Technical Constraints:**
  - All URLs must be valid and accessible
  - Campaign names should be descriptive and unique
  - Creative must comply with Facebook's ad policies
  - Landing page must match ad claim

- **Strategy Integration:**
  - Your execution plan must align with the Strategy Agent's recommendations
  - If Strategy Agent suggests specific creative approaches, implement them
  - If Strategy Agent recommends specific targeting, incorporate it

# OUTPUT FORMAT

Provide your response as a valid JSON object with the following structure:

```json
{
  "campaign_structure": {
    "objective": "LINK_CLICKS|CONVERSIONS|REACH|TRAFFIC|ENGAGEMENT|VIDEO_VIEWS|LEAD_GENERATION",
    "justification": "Why this objective was selected",
    "optimization_goal": "LINK_CLICKS|LANDING_PAGE_VIEWS|IMPRESSIONS|REACH|etc",
    "daily_budget": 16.67,
    "bid_strategy": "LOWEST_COST|COST_CAP|BID_CAP",
    "special_ad_categories": []
  },
  "targeting_strategy": {
    "geo_locations": {
      "countries": ["US"],
      "regions": [],
      "cities": []
    },
    "age_min": 18,
    "age_max": 65,
    "genders": [1, 2],
    "interests": [
      {"id": "interest_id", "name": "Interest Name"}
    ],
    "behaviors": [],
    "custom_audiences": [],
    "lookalike_audiences": [],
    "audience_size_estimate": "500K - 2M"
  },
  "placement_strategy": {
    "type": "automatic|manual",
    "platforms": ["facebook", "instagram", "messenger", "audience_network"],
    "placements": ["feed", "stories", "reels", "in_stream_video"],
    "devices": ["mobile", "desktop"],
    "reasoning": "Why these placements were selected"
  },
  "creative_strategy": {
    "ad_format": "single_image|carousel|video|collection|stories",
    "use_dynamic_creative": false,
    "primary_text": "Engaging primary text (125 chars max)",
    "headline": "Compelling headline (40 chars max)",
    "description": "Brief description (30 chars max)",
    "call_to_action": "LEARN_MORE|SHOP_NOW|SIGN_UP|DOWNLOAD|etc",
    "creative_details": {
      "image_count": 1,
      "video_count": 0,
      "carousel_cards": []
    }
  },
  "steps": [
    {
      "step_number": 1,
      "action": "create_campaign",
      "description": "Create Facebook Ads campaign with objective",
      "parameters": {
        "campaign_name": "Campaign Name",
        "objective": "LINK_CLICKS",
        "status": "PAUSED"
      },
      "dependencies": [],
      "error_handling": "If campaign creation fails, check account status and budget"
    },
    {
      "step_number": 2,
      "action": "create_ad_set",
      "description": "Create ad set with targeting and budget",
      "parameters": {
        "ad_set_name": "Ad Set Name",
        "daily_budget": 500,
        "targeting": {}
      },
      "dependencies": ["step_1"],
      "error_handling": "Verify targeting audience size is sufficient"
    }
  ],
  "budget_allocation": {
    "total_budget": {$totalBudget},
    "daily_budget": {$dailyBudget},
    "ad_set_count": 1,
    "budget_per_ad_set": {$dailyBudget},
    "pacing": "standard"
  },
  "success_criteria": {
    "deployment_success": "Campaign live with ads approved and delivering",
    "key_metrics": ["impressions", "reach", "link_clicks", "ctr", "cpc"],
    "monitoring_period": "First 7 days",
    "optimization_recommendations": [
      "Monitor frequency - keep below 2.0",
      "Review audience size - expand if too narrow",
      "Test different creative variations after 3 days"
    ]
  },
  "fallback_plans": [
    {
      "scenario": "Audience too narrow (< 50K)",
      "alternative": "Expand geographic targeting or use broader interests",
      "reasoning": "Facebook algorithm needs sufficient audience size for optimization"
    },
    {
      "scenario": "Budget too low for multiple ad sets",
      "alternative": "Consolidate into single ad set with broader targeting",
      "reasoning": "Each ad set needs minimum $5/day for effective delivery"
    }
  ],
  "reasoning": "Overall strategic reasoning for this execution plan, including how it implements the Strategy Agent's recommendations and optimizes for Facebook's algorithm"
}
```

**IMPORTANT:** 
- Return ONLY valid JSON, no markdown code fences or additional text
- Ensure all strings are properly escaped
- All numeric values should be numbers, not strings
- Budget values in cents for API calls (multiply dollars by 100)
- Include detailed reasoning for all major decisions
- Make sure your plan is executable with the available assets and budget
- Ensure targeting produces audience of at least 50,000 people
- Comply with Facebook's advertising policies
PROMPT;
    }
}
