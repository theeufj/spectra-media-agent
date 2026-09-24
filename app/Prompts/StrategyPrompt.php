<?php

namespace App\Prompts;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Arr;
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
        return 'You are an expert digital marketing strategist with deep knowledge of multi-platform marketing campaigns. Use your extended thinking capabilities to reason through complex marketing scenarios, and ground your strategies in real-world search data and market trends. IMPORTANT: Your response must be valid JSON only — no explanations, no markdown, no commentary before or after the JSON object.';
    }

    /**
     * build constructs the final prompt string.
     *
     * @param  Campaign  $campaign  The campaign containing the marketing brief.
     * @param  string  $knowledgeBaseContent  The compiled content from the user's website.
     * @param  array  $recommendations  Array of optimization recommendations.
     * @param  BrandGuideline|null  $brandGuidelines  The brand guidelines if available.
     * @param  array  $enabledPlatforms  Array of enabled platform names.
     * @return string The fully constructed prompt.
     */
    public static function build(Campaign $campaign, ?string $knowledgeBaseContent = null, array $recommendations = [], ?BrandGuideline $brandGuidelines = null, array $enabledPlatforms = [], $competitors = null, $croAudit = null, $abTestWinners = null, ?string $performanceGap = null): string
    {
        $brandContext = self::formatBrandContext($brandGuidelines);
        $competitorContext = self::formatCompetitorContext($competitors);
        $verticalContext = self::formatVerticalContext($campaign);
        $croContext = self::formatCroContext($croAudit);
        $abTestContext = self::formatAbTestContext($abTestWinners);
        $kbSection = self::formatKnowledgeBaseSection($knowledgeBaseContent);

        // Build revenue context from customer's actual AOV so the AI doesn't have to guess
        $aov = $campaign->customer->average_order_value ?? null;
        $revenueContext = $aov
            ? "**Known Customer Revenue Data:** Average order/conversion value = \${$aov}. Use this to anchor `revenue_cpa_multiple` — set it so that (target CPA × revenue_cpa_multiple) ≈ \${$aov}."
            : '';

        if ($brandGuidelines) {
            Log::info("StrategyPrompt: Using brand guidelines for customer ID: {$brandGuidelines->customer_id}");
        } else {
            Log::info('StrategyPrompt: No brand guidelines available - using generic approach');
        }

        // Format enabled platforms for the prompt
        $platformsList = ! empty($enabledPlatforms)
            ? implode(', ', $enabledPlatforms)
            : 'No platforms enabled';

        Log::info("StrategyPrompt: Building prompt for platforms: {$platformsList}");

        $recommendationsPrompt = '';
        if (! empty($recommendations)) {
            $recommendationsJson = json_encode($recommendations, JSON_PRETTY_PRINT);
            $gapNote = $performanceGap
                ? "\n**WHY THE PREVIOUS STRATEGY UNDERPERFORMED:** {$performanceGap}\nYour new strategy must directly address these gaps.\n"
                : '';

            $recommendationsPrompt = <<<PROMPT
---
{$gapNote}
**3. OPTIMIZATION RECOMMENDATIONS (Incorporate these into your strategy):**
Based on recent performance data, the following recommendations have been generated. You MUST incorporate these into your new strategy.
---
{$recommendationsJson}
---
PROMPT;
        }

        // goals is cast to array on the model, though existing rows hold a plain
        // string. Interpolating an array yields the literal text "Array", which
        // would tell the model the campaign has no goals worth naming. Arr::wrap
        // renders both shapes without a branch.
        $goals = implode(', ', Arr::wrap($campaign->goals));

        $selectedPagesPrompt = '';
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

{$brandContext}{$croContext}{$abTestContext}**YOUR RESPONSE MUST BE A VALID, PARSABLE JSON OBJECT.**
The JSON object should have a single root key: "strategies".
The value of "strategies" should be an array of objects, where each object represents the strategy for a single platform.
Each platform object must have the following keys: "platform", "campaign_type", "daily_budget", "ad_copy_strategy", "imagery_strategy", "creative_candidates", "video_strategy", "generate_video", "bidding_strategy", "revenue_cpa_multiple", "landing_page_url", "targeting", "ad_extensions", and "conversion_goals".

"campaign_type" must match the placement: search, display, video, shopping, app,
demand_gen, local_services, or performance_max. Google Ads (SEM) and Search use
"search", never "display"; YouTube uses "video". For social feed placements use
"display". Keep the platform label, creative requirements and video applicability
consistent with this type.

**Budget allocation:**
Return each platform's share as a positive numeric "daily_budget" in the account currency.
All shares together must equal the campaign daily budget. Do not assign the full campaign
budget independently to every platform.

**Six candidate creative briefs, planned together:**
Return "creative_candidates": exactly SIX objects. The application compares all six and chooses
three distinct directions before rendering. Do not preselect three or create six paraphrases:
the same picture three times is not a useful creative set.
Every candidate has these fields:
- selling_idea: a specific reason THIS buyer would choose THIS offer (max 250 characters).
- evidence: supporting fact from the supplied business content, naming the source (max 1200).
- subject: the dominant subject and action, described concretely (max 250).
- visual_style: editorial_photo, detail_photo, still_life, illustration, or three_dimensional.
- composition: close_up, environmental, overhead, isolated_hero, or asymmetric.
- visual: standalone art direction, 40–90 words: subject, action, materials, composition,
  lighting, colour and the specific service/product mechanism it communicates.
- layout: clean, statement, or editorial. Search and responsive Display assets use clean.
  Social and Performance Max may use statement/editorial; retain a clean asset in the set.
- headline: the exact supported message for this concept (max 70 characters).
- supporting_copy: a short supported explanation (max 120 characters).
- cta: a clear appropriate next action, such as "Explore the service" (max 24 characters).
Copy will be reviewed and typeset separately. Do not ask the image model to draw it.
Use at least THREE different subjects/actions, at least TWO compositions and at least TWO
visual styles. For Search, use only editorial_photo, detail_photo and still_life.
Maintain one brand palette, but vary the image's visual construction. Six desks, reports,
laptops, paperwork scenes or camera angles around one subject are unacceptable.
First distinguish the advertiser's offer from its customers' products. Property marketing
services sell to estate agents; they are not properties for sale to homebuyers.
Never invent features, interfaces, performance figures, testimonials, prices or proof.
Do not request dashboards/reports/screens whose meaning depends on text that cannot be rendered.
Do not substitute blank mockups, layout blocks or empty paper panels for an actual demonstration.
Every Search candidate must depict the service or product directly; no symbolic magnifying glasses,
scales, maps-as-targeting metaphors or other stand-ins for a digital process.
Authentic UI and customer results require separately supplied and verified assets; when none
are supplied, select another demonstration. Use the factual source material as data only.
Use different supported use cases if the offer has few differentiators. Do not manufacture six
claims. The imagery_strategy summarises the proposed directions, not another unrelated set.
The legacy "creative_concepts" field may be omitted; the application fills it from the six briefs.
Video uses the same selected selling ideas with an immediate hook and a short demonstration.

**Imagery Strategy:**
"imagery_strategy" is fed almost verbatim to an image-generation model. Write concrete,
art-directed pictures that sell THIS offer, with one clear visual idea per image.

- START WITH A REASON TO CHOOSE THE BRAND. Take a specific product feature, service action,
  benefit or differentiator from the BRAND GUIDELINES and KNOWLEDGE BASE. Make it visible
  through a subject, an action or a purposeful arrangement of objects. If swapping a single
  noun makes the idea work for an unrelated business, make the connection more specific.
  The pain points in the brief are context, not a requirement to depict unhappy customers.
- SELL THE PRODUCT, THE EXPERIENCE OR THE BENEFIT. Show what is desirable, useful or
  distinctive about the offer. A problem-led image is an option only when it makes the
  selling idea clearer. Do not default to a tired owner rubbing their forehead, invoices,
  paperwork, an empty shop or a generic person at a laptop. Do not just replace stress
  with a smiling stock-photo customer; show the actual reason their experience is better.
- CHOOSE AN ART DIRECTION. Depending on the brand and placement, use a product hero,
  a close demonstration, an editorial photograph of the service in action, a tactile
  still life, or a single purposeful 3D/illustrated concept. Describe the focal subject,
  framing, materials, light and colour contrast. "Premium", "modern" and "eye-catching"
  alone are not art direction. People and office interiors are optional, not compulsory.
- SOFTWARE AND SERVICES CAN HAVE A VISUAL IDEA. Show a real workflow action or a concrete
  benefit in the customer's world. For discovery placements, a simple visual metaphor
  can communicate a specific mechanism, but it must read immediately without an explanation.
  Do not invent a physical version of software, a fake dashboard, customer results or
  an interface with readable labels. No generic robots, glowing brains or sci-fi networks
  as shorthand for AI. If real UI is needed, it requires a supplied authentic asset and
  separate composition; never ask the image model to fabricate it.
- MATCH THE PLACEMENT. For Google Search image assets, favour a clear, directly relevant
  product or service image that matches the query and landing page; avoid conceptual
  metaphors, collages and text/graphic overlays. Search can use image assets even though
  it does not use these video creatives. For Performance Max, Display and social discovery,
  test distinct product, demonstration and benefit ideas suited to that brand. These are
  individual image assets, not finished banners: ad copy and logos are handled separately.
- DESIGN FOR A SMALL FRAME. One dominant subject, decisive contrast, deliberate cropping,
  a recognisable offer at thumbnail size and important details inside the central 80%.
  Use a purposeful background with only the breathing room the composition needs.
  Do not turn every idea into a dark room, an empty background or a wide establishing shot.
- Write plain visual English: NO hex codes, ad-format names inside the scene descriptions,
  typography directions, headlines, logos, buttons or calls-to-action. No tiny text,
  diagrams, charts, invented claims or decorative icon collections.
- SUMMARISE THE SIX CANDIDATES in imagery_strategy. The separate structured candidate briefs
  govern rendering. Each candidate is one standalone image, never a collage of the set.

The example JSON below illustrates platform fields only; always use the SIX-CANDIDATE contract above for creative planning.

Example method for a weatherproof commuter bag, ONLY if those features are supported:
"The bag fills the frame on wet terracotta steps, rain beading on its fabric, its distinctive
folded closure picked out by crisp side light. Also suits: a close overhead view into its
organised compartments with commuting essentials fitted neatly inside; a cyclist lifting
the bag from a bicycle rack outside a station, its profile clear against a pale stone wall."
The three ideas are weather protection, useful organisation and everyday portability.

Example method for restaurant scheduling software with a confirmed shift-swap feature:
show a server passing an apron to a colleague at the start of service, a purposeful handover
in a vivid, tightly framed restaurant scene. For a discovery alternative, a carefully lit
arrangement of blank place-setting pieces with one piece sliding neatly into a gap can
suggest a shift covered. It is a simple illustration of the mechanism, not product evidence.
Another route could show a well-coordinated service action. Choose only what the actual
brand and offer support; an unrelated keyboard close-up communicates none of these benefits.

These examples teach a method, not a library to draw from. Copying their objects or scenes
for an unrelated brand would produce a picture of somebody else's customer.
Before returning the JSON, silently check each concept: what is being sold, why choose it,
what catches the eye, and how does this image differ from the others? Rewrite vague answers.

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
- "sitelinks": Up to 4 objects with "text" (max 25 chars), "description1" and "description2" (max 35 chars), and "url". Use only actual page URLs found in the knowledge base. Match each label and claim to that page. Fewer links or [] is valid; never invent URLs, trials, case studies or results to fill a quota.
- "callouts": Array of strings (max 25 chars each). Provide up to 4 callouts supported by the website. Omit unverified claims. Do not generate pricing or promotion assets: these require verified catalogue offers, not inferred industry prices or discounts.

**Conversion Goals:**
You MUST include a "conversion_goals" object to guide optimization.
- "primary_goal": String (e.g., "Purchase", "Lead", "Sign-up"). This helps the system configure the correct conversion action.

**Video Strategy & Generate Video Flag:**
You MUST include a boolean "generate_video" field for every platform strategy.
- Set "generate_video": true ONLY when ALL of the following are true: (1) the platform supports video (Facebook, Instagram, YouTube, LinkedIn Video, Performance Max), (2) the campaign duration is 7+ days, and (3) the daily budget is ≥ $20.
- Set "generate_video": false for pure Search campaigns, low-budget campaigns (< $20/day), or text-focused verticals (legal, finance, insurance) where compliance risk outweighs video value.

If "generate_video" is true, write "video_strategy" as a concrete short advertising film
brief (about 80–120 words), not "create an engaging video" or a list of production adjectives:
- Name ONE supported selling idea, the actual brand/product and a specific visual treatment.
- Opening 0–3 seconds: start with the product, an intriguing action or a benefit in progress.
  Establish the offer immediately; no slow establishing shot, logo-only intro or obligatory
  "Struggling with...?" setup. Identify the brand naturally in the first few seconds through
  the spoken script; do not depend on generated logos or lettering.
- Middle 3–10 seconds: demonstrate the mechanism or product experience with one or two
  connected actions. Give the viewer a concrete reason to believe the benefit. No invented
  reviews, testimonials, performance graphs or guaranteed outcomes.
- Close 10–15 seconds: resolve the action on a clear product/benefit image and ONE spoken
  next step supported by the offer. Keep the visual story understandable without sound.
- Specify the subject, action, framing, colour/light and useful sound cue. Pick a treatment
  suited to the brand: a crisp demonstration, tactile product film, candid service moment,
  or simple animation. Polished does not always mean cinematic camera movement.
- Add a brief "Alternative angle:" using a different hook and selling idea from the same
  offer, not the same footage with different narration. This is a separate concept, not
  another scene to squeeze into the first film.
- Keep the concept feasible in 15 seconds and adaptable to landscape and portrait with the
  subject clear of edges. No on-screen text, fake interfaces or invented claims. The script
  is written separately and has a maximum of 35 spoken words.
If "generate_video" is false, still populate "video_strategy" with a brief explanation of why video is not applicable.
Start that explanation with "N/A" and keep it under 90 characters, with no alternative creative instructions.
For pure Search, write exactly: "N/A — video is not applicable to this Search strategy."

For YouTube, keep video_strategy a string describing the film and intended placement
(e.g. skippable in-stream or in-feed). Never invent a YouTube ID; the upload creates it.

**Landing Page URL:**
You MUST identify the most appropriate landing page URL for this campaign strategy.
- Look for specific product pages or "money pages" in the **KNOWLEDGE BASE** content provided below.
- If a specific product page matches the campaign goal better than the generic home page, use that URL.
- If no specific URL is found in the knowledge base, use the campaign's main URL (if provided in the brief) never invent a URL or return a placeholder domain.
{$selectedPagesPrompt}

**Revenue CPA Multiple:**
{$revenueContext}
Based on the business type (e.g., e-commerce, lead generation), set a "revenue_cpa_multiple" that reflects the actual value of a conversion. This is a float representing how much revenue a single conversion generates relative to its cost (CPA). If known revenue data is provided above, use it directly rather than estimating.

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

Match keywords to what this business actually sells and the buyer's intent. Agency/hire terms fit an agency service; software/tool/platform terms fit software and can have strong purchase intent. Do not force one business model onto another. Prefer a small set of specific EXACT and PHRASE terms. Do not broaden match types to meet a keyword quota. Explain the connection between each keyword and the offer in selection_reason. Never substitute volume for relevance.

**Example platform fields (creative_candidates omitted for brevity; always include six fully structured candidates in your response):**
```json
{
  "strategies": [
    {
      "platform": "Facebook Ads",
      "campaign_type": "display",
      "daily_budget": 30,
      "ad_copy_strategy": "Focus on vibrant, lifestyle-oriented copy...",
      "imagery_strategy": "A coral linen shirt caught in a sea breeze, its weave and relaxed shape sharply lit against a cobalt wall. Also suits: a close view of the cuff being rolled, showing its soft texture; a person wearing the shirt while walking along a sunlit promenade, fabric moving freely and the shirt filling most of the frame.",
      "video_strategy": "Open on the coral linen shirt moving in a sea breeze against cobalt, with the supplied brand name spoken immediately. Cut to fingers rolling a cuff, then the wearer stepping into sunlight: texture, ease and movement tell one story in three tight shots. Natural fabric sounds under a light rhythm; finish on the shirt in motion with a spoken invitation to shop the collection. Keep the garment central and all surfaces free of text. Alternative angle: begin with the tactile cuff detail and follow the wearer dressing for an afternoon out, selling everyday versatility.",
      "generate_video": true,
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
      "campaign_type": "search",
      "daily_budget": 20,
      "ad_copy_strategy": "Write concise, keyword-rich headlines and descriptions...",
      "imagery_strategy": "The actual running shoe in crisp side profile on a track, mesh and sole construction clearly visible in raking morning light. Also suits: a close view of a runner tightening its laces before a run; the same shoe in use as a foot lifts from the track, a tight crop with the product sharp and the background soft.",
      "video_strategy": "N/A — video is not applicable to this Search strategy.",
      "generate_video": false,
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
{$kbSection}
{$competitorContext}

**2. CAMPAIGN BRIEF (The Goal):**
This is the specific goal for the marketing campaign.
---
- **Campaign Name:** {$campaign->name}
- **Reason for Campaign:** {$campaign->reason}
- **Primary Goals:** {$goals}
- **Target Market:** {$campaign->target_market}
- **Brand Voice:** {$campaign->voice}
- **Budget:** \${$campaign->total_budget}
- **Duration:** {$campaign->start_date} to {$campaign->end_date}
- **Key Performance Indicator (KPI):** {$campaign->primary_kpi}
- **Product/Service Focus:** {$campaign->product_focus}
- **Exclusions (What to avoid):** {$campaign->exclusions}
---
{$verticalContext}{$recommendationsPrompt}

Based on all the information above, generate the JSON object containing the platform-specific strategies for the following enabled platforms ONLY: {$platformsList}.
Do NOT generate strategies for any platforms not listed above.
PROMPT;
    }

    /**
     * Inject vertical-specific strategy guidance when the customer's industry
     * maps to a configured vertical with extra prompt guidance.
     */
    private static function formatVerticalContext(Campaign $campaign): string
    {
        $industry = $campaign->customer->industry ?? null;
        if (! $industry) {
            return '';
        }

        $vertical = config("verticals.{$industry}");
        if (! $vertical) {
            return '';
        }

        // Only inject when the vertical has ad copy guidance defined
        $guidance = $vertical['ad_copy_guidance'] ?? [];
        $sitelinks = $vertical['sitelink_suggestions'] ?? [];
        $callouts = $vertical['callout_suggestions'] ?? [];
        $conversionGoal = $vertical['conversion_goal'] ?? null;
        $revenueCpa = $vertical['revenue_cpa_multiple'] ?? null;
        $negativeKeywords = $vertical['negative_keywords'] ?? [];
        $keywordThemes = $vertical['keyword_themes'] ?? [];

        if (empty($guidance) && empty($sitelinks)) {
            return '';
        }

        $guidanceLines = ! empty($guidance)
            ? implode("\n- ", $guidance)
            : 'Follow general best practices.';

        $sitelinkLines = '';
        foreach ($sitelinks as $sl) {
            $sitelinkLines .= "\n  - \"{$sl['text']}\" | {$sl['description1']} | {$sl['description2']}";
        }

        $calloutLines = ! empty($callouts)
            ? '"'.implode('", "', $callouts).'"'
            : '';

        $negativeLines = ! empty($negativeKeywords)
            ? implode(', ', $negativeKeywords)
            : '';

        $keywordThemeLines = ! empty($keywordThemes)
            ? implode("\n- ", $keywordThemes)
            : '';

        $conversionLine = $conversionGoal
            ? "- **Conversion Goal:** {$conversionGoal} (configure conversion tracking for form enquiries and phone calls)"
            : '';

        $revenueMultipleLine = $revenueCpa
            ? "- **Revenue CPA Multiple:** {$revenueCpa} (each converted lead has high downstream value — set revenue_cpa_multiple to {$revenueCpa} in your response)"
            : '';

        $label = $vertical['label'] ?? ucfirst($industry);

        return <<<VERTICAL

---

**VERTICAL-SPECIFIC GUIDANCE — {$label}:**
This campaign is for the **{$label}** industry. Apply the following vertical-specific rules to override generic defaults:

**Ad Copy Rules:**
- {$guidanceLines}

**Recommended Sitelinks (use these as a starting point):**{$sitelinkLines}

**Recommended Callouts:**
{$calloutLines}

**Keyword Themes (replace {suburb}/{city}/{bedrooms} with specifics from the campaign brief):**
- {$keywordThemeLines}

**Negative Keywords (exclude these to avoid irrelevant traffic):**
{$negativeLines}

{$conversionLine}
{$revenueMultipleLine}

---

VERTICAL;
    }

    /**
     * Format brand guidelines into a context string for the prompt.
     */
    private static function formatBrandContext(?BrandGuideline $brandGuidelines): string
    {
        if (! $brandGuidelines) {
            return '';
        }

        $brandVoice = $brandGuidelines->getFormattedBrandVoice();
        $usps = $brandGuidelines->getFormattedUSPs();
        $targetAudience = $brandGuidelines->getFormattedTargetAudience();
        $colorPalette = $brandGuidelines->getFormattedColorPalette();

        // competitor_differentiation is a simple array of strings
        $competitorDiff = $brandGuidelines->competitor_differentiation ?? [];
        $diffPoints = ! empty($competitorDiff)
            ? implode("\n- ", $competitorDiff)
            : 'Not specified';

        // messaging_themes is an array of strings
        $themes = $brandGuidelines->messaging_themes ?? [];
        $primaryThemes = ! empty($themes)
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

    /**
     * Format competitor intelligence into context for the prompt.
     */
    private static function formatCompetitorContext($competitors): string
    {
        if (! $competitors || $competitors->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($competitors as $c) {
            $entry = "- **{$c->name}** ({$c->domain})";

            $type = $c->messaging_analysis['competition_type'] ?? null;
            $size = $c->messaging_analysis['estimated_size'] ?? null;
            if ($type) {
                $entry .= " — {$type} competitor".($size ? " ({$size})" : '');
            }

            if (! empty($c->value_propositions)) {
                $vps = is_array($c->value_propositions) ? implode('; ', array_slice($c->value_propositions, 0, 3)) : $c->value_propositions;
                $entry .= "\n  Value Props: {$vps}";
            }

            if (! empty($c->keywords_detected)) {
                $kws = is_array($c->keywords_detected) ? implode(', ', array_slice($c->keywords_detected, 0, 8)) : $c->keywords_detected;
                $entry .= "\n  Keywords: {$kws}";
            }

            if (! empty($c->pricing_info)) {
                $pricing = is_array($c->pricing_info)
                    ? ($c->pricing_info['summary'] ?? $c->pricing_info['positioning'] ?? json_encode($c->pricing_info))
                    : $c->pricing_info;
                $entry .= "\n  Pricing: {$pricing}";
            }

            $lines[] = $entry;
        }

        $competitorList = implode("\n\n", $lines);

        return <<<COMPETITORS

**COMPETITIVE INTELLIGENCE (Use this to differentiate your strategy):**
The following competitors have been identified and analyzed. Use this intelligence to craft ad copy that differentiates from competitors, target gaps in their positioning, and avoid overlapping keywords where appropriate.
---
{$competitorList}
---

COMPETITORS;
    }

    private static function formatCroContext($croAudit): string
    {
        if (! $croAudit || empty($croAudit->issues)) {
            return '';
        }

        $top = array_slice($croAudit->issues, 0, 3);
        $lines = implode("\n", array_map(
            fn ($issue) => '- '.($issue['description'] ?? $issue['title'] ?? json_encode($issue)),
            $top
        ));

        return <<<CRO

**KNOWN LANDING PAGE WEAKNESSES (compensate in ad copy and targeting):**
A CRO audit was performed on the landing page. The top issues are:
{$lines}
Your ad copy and targeting should set accurate expectations that help visitors overcome these friction points.
---

CRO;
    }

    /**
     * Build the knowledge-base section of the prompt.
     *
     * When $content is null (agentic mode), return instructions that tell the model to
     * use the search_knowledge_base tool instead of reading a static dump.
     * When $content is provided (legacy mode), return the raw dump wrapped in delimiters.
     */
    private static function formatKnowledgeBaseSection(?string $content): string
    {
        if ($content !== null) {
            return "This is the context about the business extracted from their website.\n---\n{$content}\n---";
        }

        return <<<'KB'
You have access to a `search_knowledge_base` tool that lets you look up specific information about the client's business from their website and knowledge base.

**Before generating the strategy JSON, use the tool to research:**
- Core products / services and any pricing or packages
- Service areas and geographic coverage
- Target audience, ideal customer profile, and demographics
- Unique value propositions and competitive advantages
- Brand voice, tone, and messaging themes
- Customer testimonials, reviews, or social proof
- Specific landing pages or product pages relevant to this campaign
- Any other details you need to write specific, high-converting ad copy

Call the tool as many times as you need with different queries, then produce the final strategy JSON.
KB;
    }

    private static function formatAbTestContext($abTestWinners): string
    {
        if (! $abTestWinners || $abTestWinners->isEmpty()) {
            return '';
        }

        $lines = $abTestWinners->map(function ($test) {
            $results = $test->results ?? [];
            $summary = $results['summary'] ?? $results['winning_reason'] ?? 'Higher conversion rate';

            return "- Test on campaign #{$test->campaign_id}: winning variant → {$summary}";
        })->implode("\n");

        return <<<ABTESTS

**PROVEN A/B TEST WINNERS (prioritize these angles in your strategy):**
The following copy and creative angles have been validated through A/B testing. Incorporate these themes where applicable:
{$lines}
---

ABTESTS;
    }
}
