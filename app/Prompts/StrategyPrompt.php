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
     * Get the system instruction for the AI model.
     *
     * @return string The system instruction.
     */
    public static function getSystemInstruction(): string
    {
        return 'You are an expert digital marketing strategist with deep knowledge of multi-platform marketing campaigns. Use your extended thinking capabilities to reason through complex marketing scenarios, and ground your strategies in real-world search data and market trends.';
    }

    /**
     * build constructs the final prompt string.
     *
     * @param Campaign $campaign The campaign containing the marketing brief.
     * @param string $knowledgeBaseContent The compiled content from the user's website.
     * @param array $recommendations Array of optimization recommendations.
     * @param BrandGuideline|null $brandGuidelines The brand guidelines if available.
     * @param array $enabledPlatforms Array of enabled platform names.
     * @return string The fully constructed prompt.
     */
    public static function build(Campaign $campaign, string $knowledgeBaseContent, array $recommendations = [], ?BrandGuideline $brandGuidelines = null, array $enabledPlatforms = []): string
    {
        $brandContext = self::formatBrandContext($brandGuidelines);
        
        if ($brandGuidelines) {
            Log::info("StrategyPrompt: Using brand guidelines for customer ID: {$brandGuidelines->customer_id}");
        } else {
            Log::info("StrategyPrompt: No brand guidelines available - using generic approach");
        }

        // Format enabled platforms for the prompt
        $platformsList = !empty($enabledPlatforms) 
            ? implode(', ', $enabledPlatforms) 
            : 'No platforms enabled';
        
        Log::info("StrategyPrompt: Building prompt for platforms: {$platformsList}");

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

        $selectedPagesPrompt = "";
        if ($campaign->pages->isNotEmpty()) {
            $pagesList = $campaign->pages->map(function ($page) {
                return "- URL: {$page->url} (Title: {$page->title}, Type: {$page->page_type})";
            })->implode("\n");

            $selectedPagesPrompt = <<<PROMPT
**SELECTED LANDING PAGES:**
The user has explicitly selected the following pages for this campaign. You SHOULD prioritize using one of these URLs as the `landing_page_url` if appropriate.
{$pagesList}
PROMPT;
        }

        // Here, we use a HEREDOC string (`<<<PROMPT`) for a clean, multi-line prompt.
        // This is similar to using backticks for multi-line strings in Go.
        return <<<PROMPT
You are an expert digital marketing strategist. Your task is to generate a comprehensive, platform-specific marketing strategy based on the provided campaign brief, knowledge base, and brand guidelines.

{$brandContext}**YOUR RESPONSE MUST BE A VALID, PARSABLE JSON OBJECT.**
The JSON object should have a single root key: "strategies".
The value of "strategies" should be an array of objects, where each object represents the strategy for a single platform.
Each platform object must have the following keys: "platform", "ad_copy_strategy", "imagery_strategy", "video_strategy", "bidding_strategy", "revenue_cpa_multiple", "landing_page_url", "targeting", "ad_extensions", and "conversion_goals".

**Targeting Configuration:**
You MUST include a "targeting" object for each strategy that defines the audience targeting.
The "targeting" object should have the following keys:
- "interests": Array of strings (e.g., ["Shoppers", "Technology Enthusiasts"]).
- "behaviors": Array of strings (e.g., ["Frequent Travelers", "Mobile Device Users"]).
- "age_min": Integer (e.g., 18).
- "age_max": Integer (e.g., 65).
- "genders": Array of strings (e.g., ["male", "female"] or ["all"]).
- "geo_locations": Array of strings (e.g., ["United States", "Canada"]).

**Ad Extensions:**
You MUST include an "ad_extensions" object to improve ad visibility and CTR.
- "sitelinks": Array of objects, each with "text" (max 25 chars), "description1" (max 35 chars), and "description2" (max 35 chars). Provide at least 4 sitelinks.
- "callouts": Array of strings (max 25 chars each). Provide at least 4 callouts highlighting key selling points (e.g., "Free Shipping", "24/7 Support").

**Conversion Goals:**
You MUST include a "conversion_goals" object to guide optimization.
- "primary_goal": String (e.g., "Purchase", "Lead", "Sign-up"). This helps the system configure the correct conversion action.

**Video Strategy (For Video/YouTube Platforms):**
If the platform is "Video" or "YouTube", the "video_strategy" object MUST include:
- "youtube_video_id": A placeholder string (e.g., "INSERT_VIDEO_ID") or a real ID if known.
- "video_ad_format": String (e.g., "Skippable In-Stream", "In-Feed").

**Landing Page URL:**
You MUST identify the most appropriate landing page URL for this campaign strategy.
- Look for specific product pages or "money pages" in the **KNOWLEDGE BASE** content provided below.
- If a specific product page matches the campaign goal better than the generic home page, use that URL.
- If no specific URL is found in the knowledge base, use the campaign's main URL (if provided in the brief) or a placeholder that clearly indicates what the page should be (e.g., "https://example.com/product-page").
{$selectedPagesPrompt}

**Revenue CPA Multiple:**
Based on the business type (e.g., e-commerce, lead generation), estimate a "revenue_cpa_multiple". This is a float representing how much revenue a single conversion is worth compared to its cost (CPA). For example, for an e-commerce business, this might be 2.5, meaning a conversion is worth 2.5 times the target CPA. For lead generation, it might be higher, like 5.0.

**Bidding Strategy Options:**
You MUST choose one of the following bidding strategies and format it as a JSON object for the "bidding_strategy" key.

1.  `{"name": "MaximizeConversions", "parameters": {}}`
2.  `{"name": "TargetCpa", "parameters": {"targetCpaMicros": <integer>}}` - Choose a reasonable target CPA in micros (e.g., 50000000 for $50).
3.  `{"name": "TargetRoas", "parameters": {"targetRoas": <float>}}` - Choose a reasonable target ROAS (e.g., 3.5 for 350%).

**Keywords (For Search Platforms):**
Inside the "bidding_strategy" object, you MUST include a "keywords" array if the platform supports search (e.g., Google Ads).
Each item in the "keywords" array must be an object with:
- "text": The keyword string.
- "match_type": One of "BROAD", "PHRASE", or "EXACT".

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
      "revenue_cpa_multiple": 2.5,
      "landing_page_url": "https://example.com/summer-sale",
      "targeting": {
        "interests": ["Summer Fashion", "Beachwear"],
        "behaviors": ["Online Shoppers"],
        "age_min": 18,
        "age_max": 45,
        "genders": ["female"],
        "geo_locations": ["United States"]
      },
      "ad_extensions": {
        "sitelinks": [
            {"text": "Shop Summer", "description1": "New arrivals are here", "description2": "Get ready for the sun"},
            {"text": "Best Sellers", "description1": "Customer favorites", "description2": "Top rated items"}
        ],
        "callouts": ["Free Shipping", "Easy Returns", "Summer Sale"]
      },
      "conversion_goals": {
        "primary_goal": "Purchase"
      }
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
        },
        "keywords": [
            {"text": "buy running shoes", "match_type": "BROAD"},
            {"text": "best running shoes", "match_type": "PHRASE"},
            {"text": "nike running shoes", "match_type": "EXACT"}
        ]
      },
      "revenue_cpa_multiple": 3.0,
      "landing_page_url": "https://example.com/running-shoes",
      "targeting": {
        "interests": ["Running", "Marathon Training"],
        "behaviors": [],
        "age_min": 25,
        "age_max": 55,
        "genders": ["all"],
        "geo_locations": ["United States", "United Kingdom"]
      },
      "ad_extensions": {
        "sitelinks": [
            {"text": "Men's Running", "description1": "Shop men's shoes", "description2": "Built for speed"},
            {"text": "Women's Running", "description1": "Shop women's shoes", "description2": "Comfort and style"},
            {"text": "Sale Items", "description1": "Discounted gear", "description2": "Limited time offers"},
            {"text": "Store Locator", "description1": "Find a store near you", "description2": "Visit us today"}
        ],
        "callouts": ["Free Shipping > $50", "30-Day Returns", "Price Match", "Expert Advice"]
      },
      "conversion_goals": {
        "primary_goal": "Purchase"
      }
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

Based on all the information above, generate the JSON object containing the platform-specific strategies for the following enabled platforms ONLY: {$platformsList}.
Do NOT generate strategies for any platforms not listed above.
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
        
        // competitor_differentiation is a simple array of strings
        $competitorDiff = $brandGuidelines->competitor_differentiation ?? [];
        $diffPoints = !empty($competitorDiff) 
            ? implode("\n- ", $competitorDiff) 
            : 'Not specified';
        
        // messaging_themes is an array of strings
        $themes = $brandGuidelines->messaging_themes ?? [];
        $primaryThemes = !empty($themes) 
            ? implode(', ', $themes) 
            : 'Not specified';
        
        // Extract quality score - note the actual column name
        $qualityScore = $brandGuidelines->extraction_quality_score ?? 'unknown';
        
        $doNotUse = $brandGuidelines->do_not_use ? implode(', ', $brandGuidelines->do_not_use) : 'None specified';
        
        return <<<BRAND
**BRAND GUIDELINES - CRITICAL CONTEXT:**
(Extraction Quality Score: {$qualityScore}/100)

{$brandVoice}

{$usps}

{$targetAudience}

{$colorPalette}

**Competitor Differentiation:**
- {$diffPoints}

**Key Messaging Themes:** {$primaryThemes}

**DO NOT USE:** {$doNotUse}

---

BRAND;
    }
}
