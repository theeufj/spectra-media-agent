<?php

namespace App\Prompts;

use App\Models\Campaign;
use App\Models\BrandGuideline;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * StrategyPrompt is a dedicated class responsible for constructing the prompt
 * used to generate a marketing strategy from an LLM.
 *
 * In Go, you might implement this as a struct with a method, e.g.,
 * type StrategyPrompt struct { ... }
 * func (p *StrategyPrompt) Build(campaign *Campaign) (string, error) { ... }
 *
 * Centralizing prompt generation like this makes it easier to test, version,
 * and refine the prompts without changing the core application logic.
 */
class StrategyPrompt
{
    /**
     * build constructs the final prompt string.
     *
     * @param Campaign $campaign The campaign containing the marketing brief.
     * @param string $knowledgeBaseContent The compiled content from the user's website.
     * @param array $recommendations Array of optimization recommendations.
     * @param BrandGuideline|null $brandGuidelines The brand guidelines if available.
     * @return string The fully constructed prompt.
     */
    public static function build(Campaign $campaign, string $knowledgeBaseContent, array $recommendations = [], ?BrandGuideline $brandGuidelines = null): string
    {
        $brandContext = self::formatBrandContext($brandGuidelines);
        
        if ($brandGuidelines) {
            Log::info("StrategyPrompt: Using brand guidelines for customer ID: {$brandGuidelines->customer_id}");
        } else {
            Log::info("StrategyPrompt: No brand guidelines available - using generic approach");
        }

        $recommendationsPrompt = "";
        if (!empty($recommendations)) {
            $recommendationsJson = json_encode($recommendations, JSON_PRETTY_PRINT);
            $recommendationsPrompt = <<<PROMPT
---

**3. OPTIMIZATION RECOMMENDATIONS (Incorporate these into your strategy):**
Based on recent performance data, the following recommendations have been generated. You MUST incorporate these into your new strategy.
---
{$recommendationsJson}
---
PROMPT;
        }

        // Here, we use a HEREDOC string (`<<<PROMPT`) for a clean, multi-line prompt.
        // This is similar to using backticks for multi-line strings in Go.
        return <<<PROMPT
You are an expert digital marketing strategist. Your task is to generate a comprehensive, platform-specific marketing strategy based on the provided campaign brief, knowledge base, and brand guidelines.

{$brandContext}**YOUR RESPONSE MUST BE A VALID, PARSABLE JSON OBJECT.**
The JSON object should have a single root key: "strategies".
The value of "strategies" should be an array of objects, where each object represents the strategy for a single platform.
Each platform object must have the following keys: "platform", "ad_copy_strategy", "imagery_strategy", "video_strategy", "bidding_strategy", and "revenue_cpa_multiple".

**Revenue CPA Multiple:**
Based on the business type (e.g., e-commerce, lead generation), estimate a "revenue_cpa_multiple". This is a float representing how much revenue a single conversion is worth compared to its cost (CPA). For example, for an e-commerce business, this might be 2.5, meaning a conversion is worth 2.5 times the target CPA. For lead generation, it might be higher, like 5.0.

**Bidding Strategy Options:**
You MUST choose one of the following bidding strategies and format it as a JSON object for the "bidding_strategy" key.

1.  `{"name": "MaximizeConversions", "parameters": {}}`
2.  `{"name": "TargetCpa", "parameters": {"targetCpaMicros": <integer>}}` - Choose a reasonable target CPA in micros (e.g., 50000000 for $50).
3.  `{"name": "TargetRoas", "parameters": {"targetRoas": <float>}}` - Choose a reasonable target ROAS (e.g., 3.5 for 350%).

**Example Response Format:**
```json
{
  "strategies": [
    {
      "platform": "Facebook Ads",
      "ad_copy_strategy": "Focus on vibrant, lifestyle-oriented copy...",
      "imagery_strategy": "Use bright, eye-catching images of people enjoying the product...",
      "video_strategy": "Create short, engaging video clips...",
      "bidding_strategy": {
        "name": "MaximizeConversions",
        "parameters": {}
      },
      "revenue_cpa_multiple": 2.5
    },
    {
      "platform": "Google Ads (SEM)",
      "ad_copy_strategy": "Write concise, keyword-rich headlines and descriptions...",
      "imagery_strategy": "For Responsive Display Ads, use high-contrast infographics...",
      "video_strategy": "N/A for text-based search ads.",
      "bidding_strategy": {
        "name": "TargetCpa",
        "parameters": {
          "targetCpaMicros": 50000000
        }
      },
      "revenue_cpa_multiple": 3.0
    }
  ]
}
```

---

**1. KNOWLEDGE BASE (The Brand & Products):**
This is the context about the business, its brand voice, and its products, extracted from their website.
---
{$knowledgeBaseContent}
---

**2. CAMPAIGN BRIEF (The Goal):**
This is the specific goal for the marketing campaign.
---
- **Campaign Name:** {$campaign->name}
- **Reason for Campaign:** {$campaign->reason}
- **Primary Goals:** {$campaign->goals}
- **Target Market:** {$campaign->target_market}
- **Brand Voice:** {$campaign->voice}
- **Budget:** \${$campaign->total_budget}
- **Duration:** {$campaign->start_date} to {$campaign->end_date}
- **Key Performance Indicator (KPI):** {$campaign->primary_kpi}
- **Product/Service Focus:** {$campaign->product_focus}
- **Exclusions (What to avoid):** {$campaign->exclusions}
---
{$recommendationsPrompt}

Based on all the information above, generate the JSON object containing the platform-specific strategies for Facebook Ads, Google Ads (SEM), TikTok Ads, Reddit Ads, and Microsoft Advertising.
PROMPT;
    }

    /**
     * Format brand guidelines into a context string for the prompt.
     *
     * @param BrandGuideline|null $brandGuidelines
     * @return string
     */
    private static function formatBrandContext(?BrandGuideline $brandGuidelines): string
    {
        if (!$brandGuidelines) {
            return '';
        }

        $brandVoice = $brandGuidelines->getFormattedBrandVoice();
        $usps = $brandGuidelines->getFormattedUSPs();
        $targetAudience = $brandGuidelines->getFormattedTargetAudience();
        $colorPalette = $brandGuidelines->getFormattedColorPalette();
        
        $competitorDiff = $brandGuidelines->competitor_differentiation;
        $diffPoints = isset($competitorDiff['differentiation_points']) 
            ? implode("\n- ", $competitorDiff['differentiation_points']) 
            : 'Not specified';
        
        $themes = $brandGuidelines->messaging_themes;
        $primaryThemes = isset($themes['primary_themes']) 
            ? implode(', ', $themes['primary_themes']) 
            : 'Not specified';
        
        $doNotUse = $brandGuidelines->do_not_use ? implode(', ', $brandGuidelines->do_not_use) : 'None specified';
        
        return <<<BRAND
**BRAND GUIDELINES - CRITICAL CONTEXT:**

{$brandVoice}

{$usps}

{$targetAudience}

{$colorPalette}

**Competitor Differentiation:**
- {$diffPoints}
**Competitive Advantage:** {$competitorDiff['competitive_advantage']}

**Key Messaging Themes:** {$primaryThemes}
**Emotional Appeal:** {$themes['emotional_appeal']}
**Proof Points:** {$themes['proof_points']}

**DO NOT USE:** {$doNotUse}

---

BRAND;
    }
}
