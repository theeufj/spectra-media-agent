# Agent & Prompt Review - November 2025

**Review Date:** November 18, 2025  
**System:** Spectra Media Agent  
**Reviewer:** System Architecture Review

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Critical Issues](#critical-issues)
3. [Agent Analysis](#agent-analysis)
4. [Prompt Quality Assessment](#prompt-quality-assessment)
5. [Missing Capabilities](#missing-capabilities)
6. [Recommendations](#recommendations)
7. [Implementation Priorities](#implementation-priorities)

---

## Executive Summary

This document provides a comprehensive review of all agents and prompts within the Spectra Media Agent system. The review identifies critical bugs, architectural gaps, and opportunities for improvement in AI prompt engineering and agent behavior.

### Overall Assessment

**Strengths:**
- Well-designed agent architecture with proper separation of concerns
- Excellent iterative refinement approach with feedback loops
- Comprehensive validation layers (programmatic + AI review)
- Good integration of platform-specific rules

**Critical Gaps:**
- Syntax error in core strategy generation preventing API calls
- Placeholder data in seasonal strategy shifts (not production-ready)
- **Missing brand guideline extraction mechanism** - no automated way to derive brand voice, colors, typography from website scraping
- Generic prompts lacking brand voice and competitive context
- Low approval thresholds risking poor quality content

---

## Critical Issues

### 🔴 Priority 1: Must Fix Immediately

#### 1. String Concatenation Bug - `GenerateStrategy.php` Line 73

**Location:** `app/Jobs/GenerateStrategy.php:73`

**Issue:** PHP string concatenation uses `.` not `+`, causing API call to fail.

```php
// CURRENT (INCORRECT):
->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key="+$apiKey, [

// SHOULD BE:
->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=".$apiKey, [
```

**Impact:** Critical - Strategy generation will fail completely, blocking entire campaign creation workflow.

**Fix Required:** Change `+` to `.` for string concatenation.

---

#### 2. Placeholder Data in Seasonal Strategy - `ApplySeasonalStrategyShift.php`

**Location:** `app/Jobs/ApplySeasonalStrategyShift.php:44-48`

**Issue:** Uses hardcoded placeholder values instead of real campaign data.

```php
// CURRENT (PLACEHOLDER):
$campaignData = [
    'current_budget' => 50.00, // Placeholder
    'current_bidding_strategy' => 'MAXIMIZE_CONVERSIONS', // Placeholder
    'top_performing_keywords' => ['keyword1', 'keyword2'], // Placeholder
];
```

**Impact:** High - Seasonal adjustments are not based on actual campaign performance, rendering the feature non-functional.

**Fix Required:** 
- Fetch real budget from `Campaign` model
- Query Google Ads API for actual bidding strategy
- Retrieve top-performing keywords from performance data
- Add error handling for missing data

---

#### 3. Low Quality Approval Threshold - `AdminMonitorService.php`

**Location:** `app/Services/AdminMonitorService.php:130`

**Issue:** Approval threshold of 50/100 is too low, allowing poor quality content.

```php
// CURRENT:
'overall_status' => ($validationResults['is_valid'] && ($geminiFeedback['overall_score'] ?? 0) > 50) ? 'approved' : 'needs_revision',
```

**Impact:** Medium-High - Low-quality ad copy and content may be approved and deployed.

**Fix Required:** Implement tiered approval system:
- **Auto-approve:** Score ≥ 85
- **Manual review:** Score 70-84
- **Auto-reject:** Score < 70

---

### 🟡 Priority 2: Important Issues

#### 4. Google Ads Description Length - `platform_rules.php`

**Location:** `config/platform_rules.php:31`

**Issue:** Google SEM description max length is 95, but should be 90 per Google Ads API specs.

```php
// CURRENT:
'description_max_length' => 95,

// SHOULD BE:
'description_max_length' => 90,
```

**Impact:** Medium - May cause deployment failures when Google Ads rejects ads with 91-95 character descriptions.

---

## Agent Analysis

### 1. Strategy Agent (`GenerateStrategy`)

**File:** `app/Jobs/GenerateStrategy.php`  
**Prompt:** `app/Prompts/StrategyPrompt.php`  
**Status:** ⚠️ Critical bug, otherwise good architecture

#### Strengths
- ✅ Comprehensive knowledge base integration
- ✅ Recommendation feedback loop for continuous improvement
- ✅ Clear bidding strategy options with parameters
- ✅ Multi-platform strategy generation
- ✅ Structured JSON output requirements
- ✅ Extended thinking capability integration (5000 token budget)
- ✅ Proper error handling for JSON parsing

#### Issues
- 🔴 **Critical:** String concatenation syntax error (line 73)
- 🟡 Missing competitive analysis context
- 🟡 No historical campaign performance patterns
- 🟡 Bidding strategies limited (only 3 options, Google Ads supports more)
- 🟡 No platform capability awareness (e.g., Performance Max, Demand Gen)

#### Prompt Quality: **7/10**

**Strengths:**
- Clear JSON schema with examples
- Comprehensive context gathering
- Revenue CPA multiple concept is excellent

**Improvements Needed:**
```markdown
1. Add competitive positioning instructions
2. Include historical performance patterns when available
3. Expand bidding strategy options (add Performance Max, Target Impression Share, etc.)
4. Add platform capability awareness (e.g., Google's AI-powered campaigns)
5. Include seasonal context automatically
6. Add budget allocation reasoning
```

#### Recommended Prompt Enhancement

```php
// Add to StrategyPrompt.php before existing content:

**COMPETITIVE CONTEXT:**
Analyze the competitive landscape and differentiate the strategy accordingly.
---
{$competitiveContext}
---

**HISTORICAL PERFORMANCE:**
Previous campaign results to inform strategy decisions:
---
{$historicalPerformance}
---

**BIDDING STRATEGY OPTIONS (EXPANDED):**
Choose from these proven bidding strategies:

1. `{"name": "MaximizeConversions", "parameters": {}}`
2. `{"name": "MaximizeConversionValue", "parameters": {}}`
3. `{"name": "TargetCpa", "parameters": {"targetCpaMicros": <integer>}}`
4. `{"name": "TargetRoas", "parameters": {"targetRoas": <float>}}`
5. `{"name": "TargetImpressionShare", "parameters": {"location": "ABSOLUTE_TOP_OF_PAGE", "percent": <integer>}}`
6. `{"name": "ManualCpc", "parameters": {"enhancedCpcEnabled": true}}`

For Google Ads, also consider Performance Max and Demand Gen campaign types.
```

---

### 2. Ad Copy Agent (`GenerateAdCopy`)

**File:** `app/Jobs/GenerateAdCopy.php`  
**Prompt:** `app/Prompts/AdCopyPrompt.php`  
**Status:** ✅ Good with improvements needed

#### Strengths
- ✅ Iterative refinement with feedback (max 3 attempts)
- ✅ Platform-specific validation
- ✅ Dual validation (programmatic + AI)
- ✅ Proper error handling and logging
- ✅ JSON cleanup and parsing

#### Issues
- 🟡 **Missing brand voice** from knowledge base
- 🟡 No competitor differentiation instructions
- 🟡 No call-to-action best practices per platform
- 🟡 Feedback structure could be more detailed
- 🟡 No A/B testing variant generation

#### Prompt Quality: **6/10**

**Current Prompt Structure:**
```php
"You are an expert copywriter. Based on the following marketing strategy 
and platform rules for {$this->platform}, generate dynamic ad copy."
```

**Improvements Needed:**

```php
// Enhanced AdCopyPrompt structure:

"You are an expert copywriter specializing in {$this->platform} advertising.

**BRAND VOICE GUIDELINES:**
{$brandVoice} // Extract from knowledge base

**TARGET AUDIENCE:**
{$targetAudience}

**COMPETITIVE DIFFERENTIATION:**
Focus on these unique selling propositions that differentiate from competitors:
{$usp}

**PLATFORM BEST PRACTICES for {$this->platform}:**
- [Hook in first 5 words for Google Ads]
- [Include emotional trigger words for Facebook]
- [Add specific CTAs that work for this platform]

**MARKETING STRATEGY:**
{$this->strategyContent}

**PLATFORM RULES:**
{$rulesString}

**RESPONSE FORMAT:**
..."
```

---

### 3. Image Generation Agent (`GenerateImage`)

**File:** `app/Jobs/GenerateImage.php`  
**Prompt:** `app/Prompts/ImagePrompt.php` + `ImagePromptSplitterPrompt.php`  
**Status:** ✅ Excellent architecture, prompt needs enrichment

#### Strengths
- ✅ **Excellent:** AI-powered prompt splitting for multi-image scenarios
- ✅ Carousel/multi-slide support
- ✅ Fallback mechanism when splitting fails
- ✅ S3 and CloudFront integration
- ✅ Proper MIME type handling
- ✅ ImageCollateral record creation

#### Issues
- 🟡 **ImagePrompt.php is too simple** (only 3 lines)
- 🟡 Missing brand style guidelines (colors, typography, tone)
- 🟡 No aspect ratio or dimension specifications
- 🟡 Missing style guidance (realistic vs. illustrated vs. infographic)
- 🟡 No brand consistency validation

#### Prompt Quality: **4/10**

**Current Prompt (Too Basic):**
```php
"Generate a high-quality, visually compelling image for a marketing campaign 
based on the following creative strategy. The image must directly reflect 
the concepts described in the strategy.\n\n" .
"--- CREATIVE STRATEGY ---\n" .
$this->strategyContent;
```

**Recommended Enhancement:**

```php
public function getPrompt(array $brandGuidelines = []): string
{
    $brandStyle = $this->formatBrandGuidelines($brandGuidelines);
    
    return <<<PROMPT
Generate a high-quality, visually compelling marketing image that adheres to the following requirements:

**BRAND STYLE GUIDELINES:**
{$brandStyle}

**TECHNICAL SPECIFICATIONS:**
- Style: Professional, modern, high-resolution
- Format: Suitable for digital advertising
- Aspect Ratio: 1200x628 (primary), 1080x1080 (square), 1920x1080 (video thumbnail)
- Color Palette: {$brandGuidelines['colors'] ?? 'Use brand colors from strategy'}
- Typography: {$brandGuidelines['typography'] ?? 'Clean, readable fonts'}
- Mood: {$brandGuidelines['visualTone'] ?? 'Professional and engaging'}

**COMPOSITION REQUIREMENTS:**
- Clear focal point that draws attention
- Negative space for ad copy overlay (if needed)
- High contrast for visibility on various backgrounds
- Mobile-friendly (legible on small screens)

**CREATIVE STRATEGY:**
{$this->strategyContent}

**IMPORTANT:**
- Avoid text in the image (will be added separately)
- Ensure cultural sensitivity and inclusivity
- No stock photo clichés
- Brand recognition should be implicit through style
PROMPT;
}
```

---

### 4. Video Generation Agents

**Files:** 
- `app/Jobs/GenerateVideo.php`
- `app/Prompts/VideoScriptPrompt.php`
- `app/Prompts/VideoFromScriptPrompt.php`
- `app/Prompts/VideoGenerationPrompt.php`

**Status:** ⚠️ Functional but prompts too generic

#### VideoScriptPrompt Analysis

**Current Prompt Quality: 3/10** (Too basic)

**Current Implementation:**
```php
"You are a creative and concise scriptwriter for short marketing videos.
Based on the following creative strategy, write a short, engaging voiceover 
script for a video that is approximately 8-15 seconds long.

The script should be a single paragraph. Do not include scene directions, 
camera angles, or any text other than the voiceover script itself."
```

**Issues:**
- No pacing guidance (words per second)
- No hook/CTA structure requirements
- No brand voice consistency
- No emotional journey guidance
- No platform-specific considerations (TikTok vs YouTube vs Instagram)

**Recommended Enhancement:**

```php
public function getPrompt(array $brandVoice = [], string $platform = 'general'): string
{
    return <<<PROMPT
You are an expert scriptwriter for {$platform} marketing videos.

**BRAND VOICE:**
{$this->formatBrandVoice($brandVoice)}

**SCRIPT REQUIREMENTS:**
- Duration: 8-15 seconds (approximately 20-40 words)
- Pacing: 2.5 words per second for clarity
- Structure: Hook (2s) → Value Prop (6-8s) → CTA (3-5s)
- Tone: {$brandVoice['tone'] ?? 'Engaging and authentic'}

**PLATFORM BEST PRACTICES ({$platform}):**
{$this->getPlatformGuidelines($platform)}

**SCRIPT STRUCTURE:**
1. **Hook (First 2 seconds):** Grab attention with question, surprising stat, or bold statement
2. **Value Proposition (Middle 6-8 seconds):** Clearly communicate the benefit
3. **Call to Action (Final 3-5 seconds):** Clear, specific action for viewer

**CREATIVE STRATEGY:**
{$this->strategy}

**OUTPUT:**
Provide ONLY the voiceover script as a single paragraph. No scene directions, no markdown, no extra formatting.

**EXAMPLE FORMAT:**
"Are you tired of [problem]? [Product] helps you [benefit] in just [timeframe]. Try it free today at [domain]."
PROMPT;
}
```

---

### 5. Seasonal Strategy Agent (`ApplySeasonalStrategyShift`)

**File:** `app/Jobs/ApplySeasonalStrategyShift.php`  
**Prompt:** `app/Prompts/SeasonalStrategyPrompt.php`  
**Status:** 🔴 Not production-ready (placeholder data)

#### Critical Issue

**Lines 44-48:** Uses placeholder values instead of real campaign data:

```php
$campaignData = [
    'current_budget' => 50.00, // Placeholder
    'current_bidding_strategy' => 'MAXIMIZE_CONVERSIONS', // Placeholder
    'top_performing_keywords' => ['keyword1', 'keyword2'], // Placeholder
];
```

**Required Fix:**

```php
// Fetch real campaign data
$campaign = Campaign::with(['strategies', 'googleAdsCampaigns'])->findOrFail($this->campaignId);

$googleAdsCampaign = $campaign->googleAdsCampaigns->first();
$strategy = $campaign->strategies->first();

// Get real performance data
$performanceData = GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
    ->where('date', '>=', now()->subDays(30))
    ->orderBy('date', 'desc')
    ->get();

// Get top-performing keywords from Google Ads API
$topKeywords = app(GoogleAdsService::class)->getTopKeywords($googleAdsCampaign->google_ads_campaign_id, 10);

$campaignData = [
    'campaign_id' => $campaign->id,
    'current_budget' => $campaign->daily_budget ?? $campaign->total_budget / $campaign->duration_days,
    'current_bidding_strategy' => $strategy->bidding_strategy['name'] ?? 'UNKNOWN',
    'current_bidding_parameters' => $strategy->bidding_strategy['parameters'] ?? [],
    'top_performing_keywords' => $topKeywords,
    'recent_performance' => [
        'avg_cpc' => $performanceData->avg('cost_per_click'),
        'avg_ctr' => $performanceData->avg('click_through_rate'),
        'total_conversions' => $performanceData->sum('conversions'),
        'avg_conversion_rate' => $performanceData->avg('conversion_rate'),
    ],
    'spend_to_date' => $performanceData->sum('spend'),
    'days_running' => $campaign->created_at->diffInDays(now()),
];
```

#### Prompt Quality: **7/10**

**Good aspects:**
- Clear JSON structure
- Specific examples
- Multiple adjustment categories

**Improvements needed:**
- Add historical seasonal performance data
- Include competitor activity insights during season
- Add risk assessment for seasonal shifts
- Include rollback criteria

---

### 6. Recommendation Generation (`GoogleAdsRecommendationPrompt`)

**File:** `app/Prompts/GoogleAdsRecommendationPrompt.php`  
**Status:** ✅ Good structure, needs priority system

#### Prompt Quality: **7/10**

**Strengths:**
- Clear JSON output format
- Multiple recommendation types
- Rationale requirement

**Missing:**
- Priority/urgency indicators
- Expected impact estimates (e.g., "Expected ROAS increase: 15-25%")
- Risk assessment
- Implementation complexity
- Prerequisite checks

**Recommended Enhancement:**

```php
"Analyze this data and provide recommendations in a JSON array format. 
Each recommendation MUST include:

1. 'type': The recommendation category
2. 'priority': 'CRITICAL' | 'HIGH' | 'MEDIUM' | 'LOW'
3. 'target_entity': Specific campaign/ad group/keyword
4. 'parameters': Exact changes to make
5. 'rationale': Why this change is recommended
6. 'expected_impact': Quantified prediction (e.g., '+15% conversions')
7. 'risk_level': 'LOW' | 'MEDIUM' | 'HIGH'
8. 'implementation_complexity': 'SIMPLE' | 'MODERATE' | 'COMPLEX'
9. 'prerequisites': Any conditions that must be met first

**PRIORITY DEFINITIONS:**
- CRITICAL: Addresses policy violation, major bug, or >30% performance drop
- HIGH: Expected >15% improvement or significant cost savings
- MEDIUM: Expected 5-15% improvement or minor optimization
- LOW: Nice-to-have optimization, <5% expected impact"
```

---

### 7. Portfolio Optimization Service

**File:** `app/Services/Campaigns/PortfolioOptimizationService.php`  
**Status:** ✅ Good logic, needs configurability

#### Issues

**Hardcoded Thresholds:**
```php
private const ROAS_PAUSE_THRESHOLD = 0.8;
private const ROAS_INCREASE_BUDGET_THRESHOLD = 2.5;
```

**Problems:**
- Different industries have different acceptable ROAS
- E-commerce might need 3.0+ ROAS to be profitable
- Lead generation might be profitable at 1.5 ROAS
- No consideration of campaign maturity (learning phase)

**Recommended Fix:**

```php
// Move to customer-level settings or industry defaults
public function __invoke(Customer $customer): bool
{
    $settings = $customer->optimization_settings ?? $this->getIndustryDefaults($customer->industry);
    
    $pauseThreshold = $settings['roas_pause_threshold'] ?? 0.8;
    $increaseThreshold = $settings['roas_increase_threshold'] ?? 2.5;
    $minimumDataPoints = $settings['minimum_data_points'] ?? 30; // days
    
    foreach ($campaigns as $campaign) {
        // Check if campaign has enough data
        if ($campaign->days_running < $minimumDataPoints) {
            Log::info("Campaign {$campaign->id} in learning phase, skipping optimization.");
            continue;
        }
        
        $campaignRoas = $this->calculateCampaignRoas($campaign);
        $roasTrend = $this->calculateRoasTrend($campaign, 7); // 7-day trend
        
        // Consider trend, not just current ROAS
        if ($campaignRoas < $pauseThreshold && $roasTrend < 0) {
            $this->createPauseRecommendation($campaign, $campaignRoas, $roasTrend);
        }
    }
}

private function getIndustryDefaults(string $industry): array
{
    return match($industry) {
        'ecommerce' => ['roas_pause_threshold' => 1.5, 'roas_increase_threshold' => 3.0],
        'lead_generation' => ['roas_pause_threshold' => 0.8, 'roas_increase_threshold' => 2.0],
        'saas' => ['roas_pause_threshold' => 1.0, 'roas_increase_threshold' => 2.5],
        default => ['roas_pause_threshold' => 0.8, 'roas_increase_threshold' => 2.5],
    };
}
```

---

### 8. Admin Monitor Service

**File:** `app/Services/AdminMonitorService.php`  
**Status:** ✅ Comprehensive validation, threshold too low

#### Validation Rules Analysis

**Strengths:**
- ✅ Platform-specific character limits
- ✅ Exclamation mark validation
- ✅ Consecutive punctuation checks
- ✅ Headline/description count validation
- ✅ Dual validation (programmatic + AI)

**Issues:**
- 🔴 Approval threshold too low (50/100)
- 🟡 Missing brand consistency validation
- 🟡 No duplicate content detection
- 🟡 No competitor mention checks
- 🟡 No trademark/legal validation

**Recommended Enhancements:**

```php
public function reviewAdCopy(AdCopy $adCopy): array
{
    // ... existing validation ...
    
    // Add brand consistency check
    $brandConsistency = $this->checkBrandConsistency($adCopy, $campaign->knowledge_base);
    
    // Add duplicate detection
    $duplicateCheck = $this->checkForDuplicates($adCopy);
    
    // Add competitor mention check
    $competitorCheck = $this->checkCompetitorMentions($adCopy);
    
    // Tiered approval system
    $overallScore = $geminiFeedback['overall_score'] ?? 0;
    $overallStatus = match(true) {
        $overallScore >= 85 && $validationResults['is_valid'] => 'auto_approved',
        $overallScore >= 70 && $validationResults['is_valid'] => 'pending_review',
        default => 'rejected'
    };
    
    return [
        'programmatic_validation' => $validationResults,
        'gemini_feedback' => $geminiFeedback,
        'brand_consistency' => $brandConsistency,
        'duplicate_check' => $duplicateCheck,
        'competitor_check' => $competitorCheck,
        'overall_status' => $overallStatus,
        'requires_human_review' => $overallStatus === 'pending_review',
    ];
}
```

---

## Missing Capabilities

### 🔴 Critical: Brand Guideline Extraction

**Current State:** No mechanism to automatically extract brand guidelines from website scraping.

**Impact:** All prompts lack brand voice, visual style, and tone consistency because this foundational data isn't captured.

**Required Implementation:**

#### New Service: `BrandGuidelineExtractorService`

**Purpose:** Analyze scraped website content and extract brand guidelines to feed into all AI prompts.

**Location:** `app/Services/BrandGuidelineExtractorService.php`

**Responsibilities:**
1. Extract brand voice and tone from website copy
2. Identify color palette from website design
3. Detect typography patterns
4. Analyze messaging themes and value propositions
5. Identify visual style (modern, traditional, playful, etc.)
6. Extract unique selling propositions (USPs)
7. Detect target audience characteristics
8. Store guidelines in structured format

**Triggers:**
- When knowledge base is populated from website scraping
- When new pages are added to knowledge base
- Manual refresh by user

**Implementation Plan:**

```php
<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\BrandGuideline;
use App\Prompts\BrandGuidelineExtractionPrompt;
use Illuminate\Support\Facades\Log;

class BrandGuidelineExtractorService
{
    private GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Extract brand guidelines from knowledge base content
     */
    public function extractGuidelines(Customer $customer): ?BrandGuideline
    {
        try {
            // Gather all knowledge base content for this customer
            $websiteContent = $customer->user->knowledgeBase()
                ->pluck('content')
                ->implode("\n\n---\n\n");

            if (empty($websiteContent)) {
                Log::warning("No knowledge base content for customer {$customer->id}");
                return null;
            }

            // Also scrape homepage for visual analysis
            $homepageHtml = $this->scrapeHomepage($customer->website_url);
            $visualAnalysis = $this->analyzeVisualStyle($homepageHtml);

            // Build extraction prompt
            $prompt = (new BrandGuidelineExtractionPrompt(
                $websiteContent, 
                $visualAnalysis,
                $customer->industry
            ))->getPrompt();

            // Call Gemini with extended thinking for deep analysis
            $response = $this->geminiService->generateContent('gemini-2.5-pro', $prompt, [
                'thinkingConfig' => [
                    'includeThoughts' => true,
                    'thinkingBudget' => 3000
                ]
            ]);

            if (!$response || !isset($response['text'])) {
                throw new \Exception("Failed to generate brand guidelines");
            }

            // Parse response
            $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', trim($response['text']));
            $guidelines = json_decode($cleanedJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to parse guidelines: " . json_last_error_msg());
            }

            // Store guidelines
            return BrandGuideline::updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'brand_voice' => $guidelines['brand_voice'],
                    'tone_attributes' => $guidelines['tone_attributes'],
                    'color_palette' => $guidelines['color_palette'],
                    'typography' => $guidelines['typography'],
                    'visual_style' => $guidelines['visual_style'],
                    'messaging_themes' => $guidelines['messaging_themes'],
                    'unique_selling_propositions' => $guidelines['unique_selling_propositions'],
                    'target_audience' => $guidelines['target_audience'],
                    'competitor_differentiation' => $guidelines['competitor_differentiation'],
                    'brand_personality' => $guidelines['brand_personality'],
                    'do_not_use' => $guidelines['do_not_use'] ?? [],
                    'extracted_at' => now(),
                ]
            );

        } catch (\Exception $e) {
            Log::error("Error extracting brand guidelines for customer {$customer->id}: " . $e->getMessage());
            return null;
        }
    }

    private function scrapeHomepage(string $url): string
    {
        // Use existing scraping logic from CrawlPage job
        // Return full HTML for visual analysis
    }

    private function analyzeVisualStyle(string $html): array
    {
        // Extract colors, fonts, imagery style from HTML
        // Use regex or DOMDocument to parse CSS and styles
        return [
            'primary_colors' => $this->extractColors($html),
            'fonts' => $this->extractFonts($html),
            'image_style' => $this->detectImageStyle($html),
        ];
    }
}
```

#### New Prompt: `BrandGuidelineExtractionPrompt`

**Location:** `app/Prompts/BrandGuidelineExtractionPrompt.php`

```php
<?php

namespace App\Prompts;

class BrandGuidelineExtractionPrompt
{
    private string $websiteContent;
    private array $visualAnalysis;
    private string $industry;

    public function __construct(string $websiteContent, array $visualAnalysis, string $industry)
    {
        $this->websiteContent = $websiteContent;
        $this->visualAnalysis = $visualAnalysis;
        $this->industry = $industry;
    }

    public function getPrompt(): string
    {
        $visualContext = json_encode($this->visualAnalysis, JSON_PRETTY_PRINT);
        
        return <<<PROMPT
You are an expert brand strategist and copywriting analyst. Your task is to analyze a company's website content and extract comprehensive brand guidelines that will be used to maintain consistency across all marketing materials.

**INDUSTRY CONTEXT:**
This is a {$this->industry} business.

**WEBSITE CONTENT TO ANALYZE:**
---
{$this->websiteContent}
---

**VISUAL STYLE DETECTED:**
---
{$visualContext}
---

**YOUR TASK:**
Analyze all provided content and extract structured brand guidelines. Your response MUST be a valid JSON object with the following structure:

```json
{
  "brand_voice": {
    "primary_tone": "professional" | "casual" | "friendly" | "authoritative" | "playful" | "inspirational",
    "description": "2-3 sentence description of the overall brand voice",
    "examples": ["Example sentence 1 from their content", "Example sentence 2"]
  },
  "tone_attributes": [
    "professional",
    "approachable",
    "data-driven",
    "trustworthy"
  ],
  "color_palette": {
    "primary_colors": ["#HEXCODE1", "#HEXCODE2"],
    "secondary_colors": ["#HEXCODE3", "#HEXCODE4"],
    "description": "How colors are used in their branding"
  },
  "typography": {
    "heading_style": "Bold, modern sans-serif",
    "body_style": "Clean, readable",
    "fonts_detected": ["Font Family 1", "Font Family 2"]
  },
  "visual_style": {
    "overall_aesthetic": "modern" | "traditional" | "minimalist" | "bold" | "artistic",
    "imagery_style": "photography" | "illustrations" | "infographics" | "mixed",
    "description": "Description of visual approach"
  },
  "messaging_themes": [
    "Innovation and technology",
    "Customer-centric service",
    "Reliability and trust"
  ],
  "unique_selling_propositions": [
    "First USP identified from content",
    "Second USP",
    "Third USP"
  ],
  "target_audience": {
    "primary": "Description of primary audience",
    "demographics": "Age range, income level, etc.",
    "psychographics": "Values, interests, pain points",
    "language_level": "technical" | "general" | "simple"
  },
  "competitor_differentiation": [
    "How they differentiate from competitors",
    "Unique positioning points"
  ],
  "brand_personality": {
    "archetype": "Hero" | "Sage" | "Innovator" | "Caregiver" | "Rebel" | "Magician" | etc.,
    "characteristics": ["trait1", "trait2", "trait3"]
  },
  "do_not_use": [
    "Words, phrases, or approaches they explicitly avoid",
    "Competitive brand names to avoid mentioning"
  ],
  "writing_patterns": {
    "sentence_length": "short" | "medium" | "long" | "varied",
    "paragraph_style": "Description of typical paragraph structure",
    "uses_questions": true | false,
    "uses_statistics": true | false,
    "uses_testimonials": true | false,
    "call_to_action_style": "Description of typical CTAs"
  }
}
```

**ANALYSIS INSTRUCTIONS:**

1. **Brand Voice:** Read through all content carefully. How do they communicate? What's their tone? Are they formal or casual? Technical or accessible?

2. **Tone Attributes:** Identify 4-6 adjectives that best describe their communication style.

3. **Color Palette:** Extract actual hex codes from the visual analysis. Describe their color strategy.

4. **Typography:** Note the font families and how they use typography hierarchy.

5. **Visual Style:** What's their aesthetic? Modern/traditional? Photography-heavy or illustration-based?

6. **Messaging Themes:** What are the 3-5 key themes they consistently communicate?

7. **USPs:** What makes them unique? What do they emphasize as their competitive advantages?

8. **Target Audience:** Who are they speaking to? What's the sophistication level of their language?

9. **Competitor Differentiation:** How do they position themselves vs competitors?

10. **Brand Personality:** If this brand were a person, who would they be? What archetype?

11. **Do Not Use:** Are there any words/phrases they avoid? Any competitor names to never mention?

12. **Writing Patterns:** How do they structure content? Short punchy sentences or longer explanatory ones?

**IMPORTANT:**
- Base your analysis ONLY on the provided content
- Extract actual examples and quotes when possible
- Be specific with hex codes for colors
- Provide actionable guidelines, not generic descriptions
- If something isn't clear from the content, say "Not clearly defined in content"

Provide ONLY the JSON output, no additional text.
PROMPT;
    }
}
```

#### New Model: `BrandGuideline`

**Location:** `app/Models/BrandGuideline.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandGuideline extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_voice',
        'tone_attributes',
        'color_palette',
        'typography',
        'visual_style',
        'messaging_themes',
        'unique_selling_propositions',
        'target_audience',
        'competitor_differentiation',
        'brand_personality',
        'do_not_use',
        'extracted_at',
    ];

    protected $casts = [
        'brand_voice' => 'array',
        'tone_attributes' => 'array',
        'color_palette' => 'array',
        'typography' => 'array',
        'visual_style' => 'array',
        'messaging_themes' => 'array',
        'unique_selling_propositions' => 'array',
        'target_audience' => 'array',
        'competitor_differentiation' => 'array',
        'brand_personality' => 'array',
        'do_not_use' => 'array',
        'extracted_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get formatted brand voice for prompts
     */
    public function getFormattedBrandVoice(): string
    {
        $voice = $this->brand_voice;
        return "Tone: {$voice['primary_tone']}\n" .
               "Description: {$voice['description']}\n" .
               "Attributes: " . implode(', ', $this->tone_attributes);
    }

    /**
     * Get formatted color palette
     */
    public function getFormattedColorPalette(): string
    {
        return "Primary Colors: " . implode(', ', $this->color_palette['primary_colors']) . "\n" .
               "Secondary Colors: " . implode(', ', $this->color_palette['secondary_colors']) . "\n" .
               "Usage: {$this->color_palette['description']}";
    }
}
```

#### Migration

**Location:** `database/migrations/YYYY_MM_DD_create_brand_guidelines_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('brand_guidelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->json('brand_voice');
            $table->json('tone_attributes');
            $table->json('color_palette');
            $table->json('typography');
            $table->json('visual_style');
            $table->json('messaging_themes');
            $table->json('unique_selling_propositions');
            $table->json('target_audience');
            $table->json('competitor_differentiation')->nullable();
            $table->json('brand_personality');
            $table->json('do_not_use')->nullable();
            $table->timestamp('extracted_at');
            $table->timestamps();
            
            $table->index('customer_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('brand_guidelines');
    }
};
```

#### Integration Points

**Update all prompt classes to include brand guidelines:**

```php
// Example: Enhanced AdCopyPrompt
class AdCopyPrompt
{
    private string $strategyContent;
    private string $platform;
    private ?array $rules;
    private ?array $feedback;
    private ?BrandGuideline $brandGuidelines; // NEW

    public function __construct(
        string $strategyContent, 
        string $platform, 
        ?array $rules = null, 
        ?array $feedback = null,
        ?BrandGuideline $brandGuidelines = null // NEW
    ) {
        $this->strategyContent = $strategyContent;
        $this->platform = $platform;
        $this->rules = $rules ?? [];
        $this->feedback = $feedback ?? [];
        $this->brandGuidelines = $brandGuidelines; // NEW
    }

    public function getPrompt(): string
    {
        $brandContext = $this->brandGuidelines 
            ? $this->formatBrandContext($this->brandGuidelines)
            : "No specific brand guidelines provided. Use professional, engaging tone.";

        $basePrompt = "You are an expert copywriter for {$this->platform}.\n\n" .
                      "--- BRAND GUIDELINES ---\n" .
                      $brandContext . "\n\n" .
                      "--- PLATFORM RULES ---\n" .
                      // ... rest of prompt
    }

    private function formatBrandContext(BrandGuideline $guidelines): string
    {
        return <<<BRAND
**BRAND VOICE:**
{$guidelines->getFormattedBrandVoice()}

**TARGET AUDIENCE:**
{$guidelines->target_audience['primary']}
Language Level: {$guidelines->target_audience['language_level']}

**MESSAGING THEMES:**
- {implode("\n- ", $guidelines->messaging_themes)}

**UNIQUE SELLING PROPOSITIONS:**
- {implode("\n- ", $guidelines->unique_selling_propositions)}

**DO NOT USE:**
- {implode("\n- ", $guidelines->do_not_use)}

**BRAND PERSONALITY:**
Archetype: {$guidelines->brand_personality['archetype']}
Characteristics: {implode(', ', $guidelines->brand_personality['characteristics'])}
BRAND;
    }
}
```

**Trigger brand guideline extraction:**

```php
// In Customer Observer or after knowledge base population
use App\Services\BrandGuidelineExtractorService;

class CustomerObserver
{
    public function created(Customer $customer)
    {
        // After initial setup and knowledge base scraping
        dispatch(new ExtractBrandGuidelines($customer));
    }
}

// New Job
class ExtractBrandGuidelines implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Customer $customer) {}

    public function handle(BrandGuidelineExtractorService $extractor): void
    {
        $extractor->extractGuidelines($this->customer);
    }
}
```

---

### Other Missing Capabilities

#### 2. A/B Testing Variant Generation

**Current State:** Agents generate single versions of content, no A/B test variants.

**Recommendation:** Modify ad copy and image agents to generate 2-3 variants per request with strategic differences (e.g., emotional vs. logical appeal, benefit-focused vs. feature-focused).

#### 3. Competitor Analysis Integration

**Current State:** No competitive intelligence in prompts.

**Recommendation:** Create `CompetitorAnalysisService` that:
- Scrapes competitor websites
- Analyzes competitor ad copy (using SpyFu/SEMrush APIs if available)
- Identifies gaps and differentiation opportunities
- Feeds insights into strategy and ad copy generation

#### 4. Historical Performance Context

**Current State:** Limited use of past campaign data in prompts.

**Recommendation:** Create `CampaignHistoryContextService` that:
- Summarizes past campaign wins/losses
- Identifies what worked (messaging, visuals, targeting)
- Provides context to avoid repeating failures
- Integrates into StrategyPrompt and AdCopyPrompt

#### 5. Brand Consistency Validator

**Current State:** No cross-campaign brand consistency checking.

**Recommendation:** Create validation that ensures:
- Color palettes match across all campaigns
- Voice/tone is consistent
- No contradictory messaging
- USPs are consistently communicated

---

## Platform Rules Issues

### platform_rules.php Review

**Location:** `config/platform_rules.php`

#### Issues Found

1. **Google SEM Description Length**
   ```php
   // Line 31 - INCORRECT
   'description_max_length' => 95,
   
   // SHOULD BE:
   'description_max_length' => 90,
   ```

2. **Missing Rules:**
   - Responsive Display Ad dimensions (1200x628, 300x250, etc.)
   - Video ad specifications (duration, aspect ratios)
   - Character encoding rules (emoji, special characters)
   - Dynamic keyword insertion patterns

3. **Incomplete Validation:**
   ```php
   // Missing from platform_rules.php:
   'forbidden_phrases' => [
       'guaranteed results',
       'get rich quick',
       'miracle',
       'revolutionary' // without proof
   ],
   'required_elements' => [
       'call_to_action' => true,
       'value_proposition' => true,
   ],
   ```

#### Recommended Enhancements

```php
// Add to platform_rules.php

'google.display' => [
    'image_dimensions' => [
        ['width' => 1200, 'height' => 628], // Landscape
        ['width' => 1080, 'height' => 1080], // Square
        ['width' => 300, 'height' => 250], // Medium rectangle
        ['width' => 336, 'height' => 280], // Large rectangle
        ['width' => 728, 'height' => 90], // Leaderboard
    ],
    'file_size_max_kb' => 150,
    'headline_max_length' => 30,
    'description_max_length' => 90,
],

'google.video' => [
    'min_duration_seconds' => 6,
    'max_duration_seconds' => 15,
    'aspect_ratios' => ['16:9', '1:1', '9:16'],
    'file_size_max_mb' => 1024,
    'formats' => ['MP4', 'MOV', 'AVI'],
],

'global_content_rules' => [
    'forbidden_claims' => [
        'guaranteed',
        'miracle',
        '#1 in the world' // without proof
    ],
    'required_disclosures' => [
        'terms_conditions_for_offers' => true,
        'pricing_transparency' => true,
    ],
    'accessibility' => [
        'min_color_contrast_ratio' => 4.5,
        'text_on_images_readable' => true,
    ],
],
```

---

## Recommendations

### Priority 1: Critical Fixes (This Week)

1. ✅ **Fix string concatenation bug** in `GenerateStrategy.php` line 73
2. ✅ **Implement real data fetching** in `ApplySeasonalStrategyShift.php`
3. ✅ **Raise approval threshold** from 50 to 75 in `AdminMonitorService.php`
4. ✅ **Fix Google Ads description length** from 95 to 90
5. ✅ **Implement Brand Guideline Extraction** - Critical for all prompt quality

### Priority 2: High-Impact Improvements (Next 2 Weeks)

6. **Enhance StrategyPrompt** with competitive analysis and expanded bidding strategies
7. **Enrich AdCopyPrompt** with brand voice from brand guidelines
8. **Upgrade ImagePrompt** with technical specifications and brand style
9. **Rewrite VideoScriptPrompt** with pacing, structure, and brand voice
10. **Make ROAS thresholds configurable** per customer/industry

### Priority 3: Feature Additions (Next Month)

11. **Implement A/B testing variant generation** for ad copy and images
12. **Create CompetitorAnalysisService** for competitive intelligence
13. **Build CampaignHistoryContextService** for historical learnings
14. **Add recommendation priority system** with impact estimates
15. **Implement brand consistency validator** across campaigns

### Priority 4: Quality Enhancements (Ongoing)

16. **Add tiered approval system** (auto/manual/reject)
17. **Enhance platform rules** with display, video, and accessibility rules
18. **Implement duplicate content detection**
19. **Add legal/trademark validation**
20. **Create prompt versioning system** for A/B testing prompts themselves

---

## Implementation Priorities

### Week 1: Critical Fixes
- [ ] Fix GenerateStrategy.php string concatenation bug
- [ ] Fix ApplySeasonalStrategyShift.php placeholder data
- [ ] Update AdminMonitorService threshold to 75
- [ ] Fix platform_rules.php Google description length
- [ ] Create brand_guidelines table migration
- [ ] Implement BrandGuidelineExtractorService
- [ ] Create BrandGuidelineExtractionPrompt
- [ ] Add BrandGuideline model

### Week 2: Brand Integration
- [ ] Update all prompts to accept BrandGuideline parameter
- [ ] Integrate brand guidelines into AdCopyPrompt
- [ ] Integrate brand guidelines into ImagePrompt
- [ ] Integrate brand guidelines into VideoScriptPrompt
- [ ] Integrate brand guidelines into StrategyPrompt
- [ ] Create ExtractBrandGuidelines job
- [ ] Trigger brand extraction after knowledge base population
- [ ] Add UI for viewing/editing brand guidelines

### Week 3: Prompt Enhancements
- [ ] Enhance StrategyPrompt with competitive context
- [ ] Rewrite VideoScriptPrompt with structure and pacing
- [ ] Upgrade ImagePrompt with technical specifications
- [ ] Add recommendation priority system to GoogleAdsRecommendationPrompt
- [ ] Expand bidding strategy options in StrategyPrompt

### Week 4: Configurability & Optimization
- [ ] Make ROAS thresholds configurable per customer
- [ ] Add industry-specific optimization settings
- [ ] Implement campaign maturity checks (learning phase)
- [ ] Add ROAS trend analysis (not just current value)
- [ ] Create customer optimization settings UI

### Month 2: New Features
- [ ] Implement A/B testing variant generation
- [ ] Create CompetitorAnalysisService
- [ ] Build CampaignHistoryContextService
- [ ] Add tiered approval system
- [ ] Enhance platform rules with display/video specs
- [ ] Implement brand consistency validator
- [ ] Add duplicate content detection

---

## Testing Recommendations

### Unit Tests Needed

1. **BrandGuidelineExtractorService**
   - Test guideline extraction from sample website content
   - Test handling of missing/incomplete content
   - Test color extraction from HTML
   - Test font detection

2. **Enhanced Prompts**
   - Test prompt generation with brand guidelines
   - Test prompt generation without brand guidelines (fallback)
   - Test brand context formatting

3. **Validation Updates**
   - Test new approval thresholds
   - Test tiered approval system
   - Test brand consistency validation

### Integration Tests Needed

1. **End-to-End Campaign Creation**
   - Test website scrape → brand extraction → strategy → ad copy → deployment flow
   - Verify brand consistency across all generated content
   - Test fallback when brand extraction fails

2. **Seasonal Strategy Shift**
   - Test with real campaign data
   - Verify actual Google Ads API integration
   - Test recommendation application

3. **Portfolio Optimization**
   - Test with various industry configurations
   - Test ROAS calculation accuracy
   - Test trend analysis vs single-point ROAS

---

## Monitoring & Observability

### Metrics to Track

1. **Prompt Performance**
   - Average score of generated content by prompt type
   - Approval rate (auto/manual/reject) by content type
   - Iteration count before approval (should be ≤2 on average)

2. **Brand Consistency**
   - Brand guideline extraction success rate
   - Content consistency scores across campaigns
   - User manual override frequency (indicates poor extraction)

3. **Business Impact**
   - Campaign ROAS by strategy prompt version
   - Ad copy CTR by prompt version
   - Time to campaign deployment (should decrease with better prompts)

### Logging Enhancements

```php
// Add to all AI generation jobs
Log::info("AI Content Generation", [
    'job' => class_basename($this),
    'prompt_version' => '1.0', // Add versioning
    'brand_guidelines_used' => isset($brandGuidelines),
    'iteration' => $attempt,
    'model' => 'gemini-2.5-pro',
    'input_tokens' => $response['usage']['prompt_tokens'] ?? null,
    'output_tokens' => $response['usage']['completion_tokens'] ?? null,
    'score' => $reviewResults['overall_score'] ?? null,
]);
```

---

## Conclusion

The Spectra Media Agent system has a solid architectural foundation, but requires immediate attention to critical bugs and significant enhancement to prompts for production-quality output.

**Most Critical Next Step:** Implement brand guideline extraction as it's the foundation for all other prompt improvements.

**Key Success Factors:**
1. Fix critical bugs immediately (string concatenation, placeholder data)
2. Implement brand guideline extraction before enhancing prompts
3. Systematically integrate brand guidelines into all prompts
4. Increase quality thresholds and implement tiered approval
5. Make optimization parameters configurable per customer

With these improvements, the system will generate significantly higher quality, brand-consistent content that drives better campaign performance.

---

**Document Version:** 1.0  
**Last Updated:** November 18, 2025  
**Next Review:** December 2025
