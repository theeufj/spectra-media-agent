# Brand Guideline Extraction - Implementation Plan

**Feature:** Automated Brand Guideline Extraction from Website Content  
**Priority:** Critical - Foundation for all AI-generated content quality  
**Target Completion:** Week 1 Sprint  
**Status:** 🟡 Planning → Implementation

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Database Schema](#database-schema)
4. [Implementation Steps](#implementation-steps)
5. [Code Implementation](#code-implementation)
6. [Integration Points](#integration-points)
7. [Testing Strategy](#testing-strategy)
8. [Rollout Plan](#rollout-plan)

---

## Overview

### Problem Statement

Currently, all AI-generated content (ad copy, images, videos, strategies) lacks brand consistency because we don't extract and utilize brand guidelines from the customer's website. This results in:

- Generic, non-branded content
- Inconsistent voice and tone across campaigns
- No visual style consistency
- Missing brand personality in messaging
- No unique selling proposition (USP) integration

### Solution

Implement an automated Brand Guideline Extraction service that:

1. Analyzes scraped website content from the knowledge base
2. Extracts structured brand guidelines using AI
3. Stores guidelines in a dedicated database table
4. Makes guidelines available to all content generation prompts
5. Updates guidelines when website content changes

### Success Criteria

- ✅ Brand guidelines extracted for 100% of customers with websites
- ✅ Guidelines include: voice, tone, colors, typography, USPs, target audience
- ✅ All prompt classes accept and utilize brand guidelines
- ✅ Content approval scores increase by 20%+ after implementation
- ✅ Manual override rate decreases (indicates good extraction quality)

---

## Architecture

### Component Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                     Website Scraping Flow                        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Knowledge Base                              │
│  (Stores scraped website content as text chunks)                │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              BrandGuidelineExtractorService                      │
│  ┌────────────────────────────────────────────────────────┐    │
│  │  1. Gather knowledge base content                      │    │
│  │  2. Scrape homepage HTML for visual analysis           │    │
│  │  3. Extract colors, fonts, image styles                │    │
│  │  4. Build comprehensive prompt                         │    │
│  │  5. Call Gemini 2.5 Pro with extended thinking         │    │
│  │  6. Parse structured JSON response                     │    │
│  │  7. Store in brand_guidelines table                    │    │
│  └────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    BrandGuideline Model                          │
│  (Stores structured brand guidelines per customer)              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              Content Generation Prompts                          │
│  ┌────────────────────────────────────────────────────────┐    │
│  │  - StrategyPrompt (uses brand voice & USPs)            │    │
│  │  - AdCopyPrompt (uses brand voice & tone)              │    │
│  │  - ImagePrompt (uses colors, visual style)             │    │
│  │  - VideoScriptPrompt (uses brand personality)          │    │
│  └────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

### Data Flow

```
Customer Created
      ↓
Website URL Provided
      ↓
ScrapeCustomerWebsite Job (existing)
      ↓
Knowledge Base Populated
      ↓
[NEW] ExtractBrandGuidelines Job Dispatched
      ↓
BrandGuidelineExtractorService Called
      ↓
Gemini 2.5 Pro Analysis
      ↓
BrandGuideline Record Created/Updated
      ↓
Available to All Content Generation Jobs
```

### Trigger Points

1. **Initial Customer Setup** - After knowledge base is first populated
2. **Website Content Update** - When customer updates their website URL
3. **Manual Refresh** - Admin/user requests guideline re-extraction
4. **Scheduled Refresh** - Weekly/monthly background job to catch website changes

---

## Database Schema

### New Table: `brand_guidelines`

```sql
CREATE TABLE brand_guidelines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    
    -- Brand Voice & Tone
    brand_voice JSON NOT NULL COMMENT 'Primary tone, description, examples',
    tone_attributes JSON NOT NULL COMMENT 'Array of tone adjectives',
    writing_patterns JSON COMMENT 'Sentence length, paragraph style, CTA style',
    
    -- Visual Identity
    color_palette JSON NOT NULL COMMENT 'Primary/secondary colors with descriptions',
    typography JSON NOT NULL COMMENT 'Font families, heading/body styles',
    visual_style JSON NOT NULL COMMENT 'Overall aesthetic, imagery style',
    
    -- Messaging
    messaging_themes JSON NOT NULL COMMENT 'Key themes consistently communicated',
    unique_selling_propositions JSON NOT NULL COMMENT 'USPs and differentiators',
    
    -- Audience & Positioning
    target_audience JSON NOT NULL COMMENT 'Demographics, psychographics, language level',
    competitor_differentiation JSON COMMENT 'How they position vs competitors',
    brand_personality JSON NOT NULL COMMENT 'Archetype and characteristics',
    
    -- Constraints
    do_not_use JSON COMMENT 'Forbidden words, phrases, approaches',
    
    -- Metadata
    extraction_quality_score INT COMMENT 'AI confidence score 0-100',
    extracted_at TIMESTAMP NOT NULL,
    last_verified_at TIMESTAMP COMMENT 'When user last reviewed/approved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer_id (customer_id),
    INDEX idx_extracted_at (extracted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### JSON Structure Examples

#### `brand_voice` JSON Schema
```json
{
  "primary_tone": "professional",
  "description": "The brand communicates with authority and expertise while remaining approachable and helpful.",
  "examples": [
    "We help businesses grow with data-driven marketing strategies.",
    "Your success is our priority—let's build something amazing together."
  ]
}
```

#### `color_palette` JSON Schema
```json
{
  "primary_colors": ["#0066CC", "#FFFFFF"],
  "secondary_colors": ["#FF6B35", "#F7F7F7"],
  "description": "Primary blue conveys trust and professionalism. Orange accent adds energy and calls attention to CTAs.",
  "usage_notes": "Blue for headers and primary buttons, orange for secondary CTAs"
}
```

#### `target_audience` JSON Schema
```json
{
  "primary": "Small to mid-sized business owners and marketing managers",
  "demographics": "Ages 30-55, $75k+ income, urban/suburban",
  "psychographics": "Growth-minded, data-driven decision makers, value efficiency and ROI",
  "pain_points": ["Limited marketing budget", "Lack of in-house expertise", "Need measurable results"],
  "language_level": "professional but accessible, avoid heavy jargon"
}
```

---

## Implementation Steps

### Phase 1: Core Infrastructure (Days 1-2)

- [ ] Create migration for `brand_guidelines` table
- [ ] Create `BrandGuideline` model with relationships and casts
- [ ] Create `BrandGuidelineExtractionPrompt` class
- [ ] Create `BrandGuidelineExtractorService` class
- [ ] Create `ExtractBrandGuidelines` job

### Phase 2: Visual Analysis (Day 3)

- [ ] Implement HTML color extraction
- [ ] Implement font family detection
- [ ] Implement image style analysis
- [ ] Add CSS parsing for visual elements

### Phase 3: Integration (Days 4-5)

- [ ] Update `CustomerObserver` to trigger extraction
- [ ] Add extraction trigger to knowledge base completion
- [ ] Create manual refresh endpoint/command
- [ ] Update all prompt classes to accept `BrandGuideline`

### Phase 4: Prompt Enhancements (Days 6-7)

- [ ] Update `StrategyPrompt` with brand guidelines
- [ ] Update `AdCopyPrompt` with brand guidelines
- [ ] Update `ImagePrompt` with brand guidelines
- [ ] Update `VideoScriptPrompt` with brand guidelines
- [ ] Update `SeasonalStrategyPrompt` with brand guidelines

### Phase 5: Testing & Validation (Days 8-9)

- [ ] Unit tests for extractor service
- [ ] Unit tests for prompt classes
- [ ] Integration tests for full flow
- [ ] Test with 5-10 real customer websites
- [ ] Quality validation and tuning

### Phase 6: UI & Monitoring (Day 10)

- [ ] Add brand guidelines view/edit page
- [ ] Add extraction status indicators
- [ ] Add logging and monitoring
- [ ] Add success metrics tracking

---

## Code Implementation

### 1. Database Migration

**File:** `database/migrations/2025_11_18_create_brand_guidelines_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_guidelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            
            // Brand Voice & Tone
            $table->json('brand_voice');
            $table->json('tone_attributes');
            $table->json('writing_patterns')->nullable();
            
            // Visual Identity
            $table->json('color_palette');
            $table->json('typography');
            $table->json('visual_style');
            
            // Messaging
            $table->json('messaging_themes');
            $table->json('unique_selling_propositions');
            
            // Audience & Positioning
            $table->json('target_audience');
            $table->json('competitor_differentiation')->nullable();
            $table->json('brand_personality');
            
            // Constraints
            $table->json('do_not_use')->nullable();
            
            // Metadata
            $table->integer('extraction_quality_score')->nullable();
            $table->timestamp('extracted_at');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            
            $table->index('customer_id');
            $table->index('extracted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_guidelines');
    }
};
```

### 2. BrandGuideline Model

**File:** `app/Models/BrandGuideline.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandGuideline extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_voice',
        'tone_attributes',
        'writing_patterns',
        'color_palette',
        'typography',
        'visual_style',
        'messaging_themes',
        'unique_selling_propositions',
        'target_audience',
        'competitor_differentiation',
        'brand_personality',
        'do_not_use',
        'extraction_quality_score',
        'extracted_at',
        'last_verified_at',
    ];

    protected $casts = [
        'brand_voice' => 'array',
        'tone_attributes' => 'array',
        'writing_patterns' => 'array',
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
        'last_verified_at' => 'datetime',
    ];

    /**
     * Get the customer that owns the brand guidelines
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get formatted brand voice for use in prompts
     */
    public function getFormattedBrandVoice(): string
    {
        $voice = $this->brand_voice;
        $output = "**Primary Tone:** {$voice['primary_tone']}\n";
        $output .= "**Description:** {$voice['description']}\n";
        $output .= "**Key Attributes:** " . implode(', ', $this->tone_attributes) . "\n";
        
        if (!empty($voice['examples'])) {
            $output .= "**Examples from their content:**\n";
            foreach ($voice['examples'] as $example) {
                $output .= "- \"{$example}\"\n";
            }
        }
        
        return $output;
    }

    /**
     * Get formatted color palette for prompts
     */
    public function getFormattedColorPalette(): string
    {
        $palette = $this->color_palette;
        $output = "**Primary Colors:** " . implode(', ', $palette['primary_colors']) . "\n";
        $output .= "**Secondary Colors:** " . implode(', ', $palette['secondary_colors'] ?? []) . "\n";
        $output .= "**Usage:** {$palette['description']}\n";
        
        return $output;
    }

    /**
     * Get formatted USPs for prompts
     */
    public function getFormattedUSPs(): string
    {
        return implode("\n", array_map(
            fn($usp, $index) => ($index + 1) . ". {$usp}",
            $this->unique_selling_propositions,
            array_keys($this->unique_selling_propositions)
        ));
    }

    /**
     * Get formatted target audience for prompts
     */
    public function getFormattedTargetAudience(): string
    {
        $audience = $this->target_audience;
        $output = "**Primary Audience:** {$audience['primary']}\n";
        $output .= "**Demographics:** {$audience['demographics']}\n";
        $output .= "**Psychographics:** {$audience['psychographics']}\n";
        $output .= "**Language Level:** {$audience['language_level']}\n";
        
        if (!empty($audience['pain_points'])) {
            $output .= "**Pain Points:** " . implode(', ', $audience['pain_points']) . "\n";
        }
        
        return $output;
    }

    /**
     * Get complete formatted guidelines for inclusion in prompts
     */
    public function getFormattedGuidelines(): string
    {
        return <<<GUIDELINES
=== BRAND GUIDELINES ===

{$this->getFormattedBrandVoice()}

{$this->getFormattedTargetAudience()}

**UNIQUE SELLING PROPOSITIONS:**
{$this->getFormattedUSPs()}

**MESSAGING THEMES:**
{$this->getFormattedMessagingThemes()}

**VISUAL STYLE:**
{$this->getFormattedVisualStyle()}

{$this->getFormattedColorPalette()}

**BRAND PERSONALITY:**
Archetype: {$this->brand_personality['archetype']}
Characteristics: {$this->getFormattedCharacteristics()}

{$this->getFormattedConstraints()}

=== END BRAND GUIDELINES ===
GUIDELINES;
    }

    /**
     * Get formatted messaging themes
     */
    private function getFormattedMessagingThemes(): string
    {
        return implode("\n", array_map(
            fn($theme) => "- {$theme}",
            $this->messaging_themes
        ));
    }

    /**
     * Get formatted visual style
     */
    private function getFormattedVisualStyle(): string
    {
        $style = $this->visual_style;
        return "Aesthetic: {$style['overall_aesthetic']}\n" .
               "Imagery: {$style['imagery_style']}\n" .
               "Description: {$style['description']}";
    }

    /**
     * Get formatted brand characteristics
     */
    private function getFormattedCharacteristics(): string
    {
        return implode(', ', $this->brand_personality['characteristics']);
    }

    /**
     * Get formatted constraints
     */
    private function getFormattedConstraints(): string
    {
        if (empty($this->do_not_use)) {
            return '';
        }
        
        return "**DO NOT USE:**\n" . implode("\n", array_map(
            fn($item) => "- {$item}",
            $this->do_not_use
        ));
    }

    /**
     * Check if guidelines are fresh (extracted within last 30 days)
     */
    public function isFresh(): bool
    {
        return $this->extracted_at->isAfter(now()->subDays(30));
    }

    /**
     * Check if guidelines have been verified by user
     */
    public function isVerified(): bool
    {
        return !is_null($this->last_verified_at);
    }
}
```

### 3. BrandGuidelineExtractionPrompt

**File:** `app/Prompts/BrandGuidelineExtractionPrompt.php`

```php
<?php

namespace App\Prompts;

class BrandGuidelineExtractionPrompt
{
    private string $websiteContent;
    private array $visualAnalysis;
    private string $industry;

    public function __construct(
        string $websiteContent, 
        array $visualAnalysis, 
        string $industry
    ) {
        $this->websiteContent = $websiteContent;
        $this->visualAnalysis = $visualAnalysis;
        $this->industry = $industry;
    }

    public function getPrompt(): string
    {
        $visualContext = json_encode($this->visualAnalysis, JSON_PRETTY_PRINT);
        
        return <<<PROMPT
You are an expert brand strategist and copywriting analyst with deep expertise in marketing psychology and brand positioning. Your task is to analyze a company's website content and extract comprehensive, actionable brand guidelines.

**INDUSTRY CONTEXT:**
This is a **{$this->industry}** business. Consider industry-specific conventions, customer expectations, and competitive positioning norms for this sector.

**WEBSITE CONTENT TO ANALYZE:**
---
{$this->websiteContent}
---

**VISUAL STYLE ANALYSIS:**
---
{$visualContext}
---

**YOUR MISSION:**
Analyze the provided content deeply and extract structured brand guidelines that will ensure consistency across all marketing materials. Pay special attention to subtle patterns in language, recurring themes, and implicit brand values.

**CRITICAL: Your response MUST be a valid JSON object with this exact structure:**

```json
{
  "brand_voice": {
    "primary_tone": "professional" | "casual" | "friendly" | "authoritative" | "playful" | "inspirational" | "empathetic" | "bold",
    "description": "A detailed 2-3 sentence description of the overall brand voice and how they communicate with their audience",
    "examples": [
      "Actual sentence or phrase from their content that exemplifies their voice",
      "Another example that shows their communication style",
      "A third example demonstrating their tone"
    ]
  },
  "tone_attributes": [
    "professional",
    "approachable",
    "data-driven",
    "innovative",
    "trustworthy",
    "empowering"
  ],
  "writing_patterns": {
    "sentence_length": "short" | "medium" | "long" | "varied",
    "paragraph_style": "Description of typical paragraph structure and length",
    "uses_questions": true | false,
    "uses_statistics": true | false,
    "uses_testimonials": true | false,
    "uses_storytelling": true | false,
    "call_to_action_style": "Description of how they typically phrase CTAs",
    "punctuation_style": "formal" | "casual" | "enthusiastic",
    "emoji_usage": "none" | "minimal" | "moderate" | "heavy"
  },
  "color_palette": {
    "primary_colors": ["#HEXCODE1", "#HEXCODE2"],
    "secondary_colors": ["#HEXCODE3", "#HEXCODE4"],
    "description": "How and when each color is used in their branding",
    "usage_notes": "Specific guidance on color application"
  },
  "typography": {
    "heading_style": "Description of heading font characteristics",
    "body_style": "Description of body text characteristics",
    "fonts_detected": ["Font Family 1", "Font Family 2"],
    "font_weights": "Typical weight usage (light, regular, bold, etc.)",
    "letter_spacing": "normal" | "tight" | "wide"
  },
  "visual_style": {
    "overall_aesthetic": "modern" | "traditional" | "minimalist" | "bold" | "artistic" | "corporate" | "playful" | "luxurious",
    "imagery_style": "photography" | "illustrations" | "infographics" | "mixed" | "video-first",
    "description": "Detailed description of their visual approach and what makes it distinctive",
    "color_treatment": "vibrant" | "muted" | "high-contrast" | "monochromatic",
    "layout_preference": "clean and spacious" | "content-dense" | "grid-based" | "asymmetric"
  },
  "messaging_themes": [
    "Primary theme they communicate about (e.g., 'Innovation and cutting-edge technology')",
    "Secondary theme (e.g., 'Customer success and support')",
    "Tertiary theme (e.g., 'Transparency and trust')",
    "Additional themes as identified"
  ],
  "unique_selling_propositions": [
    "First USP: What makes them unique and why customers should choose them",
    "Second USP: Another key differentiator",
    "Third USP: Additional competitive advantage"
  ],
  "target_audience": {
    "primary": "Detailed description of their primary target audience",
    "demographics": "Age range, income level, education, job titles, etc.",
    "psychographics": "Values, interests, lifestyle, aspirations, pain points",
    "pain_points": [
      "Key problem their audience faces",
      "Another pain point they address",
      "Additional challenges"
    ],
    "language_level": "technical" | "professional" | "general" | "simple",
    "familiarity_assumption": "expert" | "intermediate" | "beginner"
  },
  "competitor_differentiation": [
    "How they position themselves differently from competitors",
    "Key competitive advantages they emphasize",
    "What they do that competitors don't"
  ],
  "brand_personality": {
    "archetype": "Hero" | "Sage" | "Innovator" | "Caregiver" | "Rebel" | "Magician" | "Explorer" | "Creator" | "Ruler" | "Jester" | "Everyman" | "Lover",
    "characteristics": [
      "Personality trait 1",
      "Personality trait 2",
      "Personality trait 3",
      "Personality trait 4"
    ],
    "if_brand_were_person": "1-2 sentence description of the brand as if it were a person"
  },
  "do_not_use": [
    "Words, phrases, or approaches they explicitly avoid or that contradict their brand",
    "Industry jargon they steer clear of",
    "Competitor brand names to never mention",
    "Any negative or inappropriate terms"
  ],
  "extraction_quality_score": 85,
  "extraction_notes": "Brief notes on extraction quality, any missing information, or areas of uncertainty"
}
```

**ANALYSIS GUIDELINES:**

1. **Brand Voice Analysis:**
   - Read multiple pages/sections to identify consistent patterns
   - Note the emotional quality of their writing
   - Identify if they use first person ("we"), second person ("you"), or third person
   - Look for humor, empathy, authority, or other emotional tones
   - Extract actual quotes that exemplify their voice

2. **Tone Attributes:**
   - Choose 4-8 adjectives that accurately describe their communication
   - Be specific: "data-driven" not just "professional"
   - Consider both what they say and how they say it

3. **Writing Patterns:**
   - Analyze sentence structure: short and punchy vs. long and explanatory
   - Note paragraph length and structure
   - Identify if they use rhetorical questions
   - Check for data, statistics, or social proof
   - Observe CTA patterns (imperative vs. suggestive, etc.)

4. **Visual Analysis:**
   - Extract actual hex codes from the visual analysis
   - Describe the mood created by their color choices
   - Note primary action colors (CTAs) vs. background colors

5. **Messaging Themes:**
   - Identify 3-5 core themes they consistently communicate
   - Look for recurring topics, values, or benefits mentioned
   - Note what they emphasize most frequently

6. **USPs:**
   - Extract explicit claims of uniqueness or superiority
   - Identify implicit differentiators in how they describe their offering
   - Look for "only", "first", "best", "exclusive" type language

7. **Target Audience:**
   - Infer audience from language complexity and topics
   - Note who they address directly in copy
   - Identify pain points they acknowledge or problems they solve
   - Determine technical vs. general language level

8. **Competitor Differentiation:**
   - Look for comparative language or positioning statements
   - Note unique features or approaches they emphasize
   - Identify gaps they claim to fill in the market

9. **Brand Personality:**
   - Assign one of the 12 brand archetypes that best fits
   - Choose 4-5 personality characteristics
   - Imagine the brand as a person and describe them

10. **Do Not Use:**
    - Identify words or phrases notably absent
    - Note if they avoid jargon, hyperbole, or specific terms
    - List competitor names (don't mention in our content)
    - Flag any language that would contradict their brand

11. **Quality Score:**
    - Rate your confidence in the extraction (0-100)
    - 90-100: Exceptional clarity and consistency in source material
    - 70-89: Good extraction, some inference required
    - 50-69: Limited source material, significant inference
    - Below 50: Insufficient data for reliable extraction

**IMPORTANT INSTRUCTIONS:**

- Base analysis ONLY on provided content, do not make assumptions
- Extract actual quotes and examples where possible
- Be specific and actionable, not generic
- If information is unclear or missing, note it in extraction_notes
- Provide actual hex codes for colors, not color names
- The JSON must be valid and parseable
- Do NOT include markdown code fences (```json) in your response
- Response must start with { and end with }

**OUTPUT:**
Provide ONLY the JSON object, no additional text, explanations, or markdown formatting.
PROMPT;
    }
}
```

### 4. BrandGuidelineExtractorService

**File:** `app/Services/BrandGuidelineExtractorService.php`

```php
<?php

namespace App\Services;

use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Prompts\BrandGuidelineExtractionPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class BrandGuidelineExtractorService
{
    private GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Extract brand guidelines from customer's website and knowledge base
     */
    public function extractGuidelines(Customer $customer): ?BrandGuideline
    {
        try {
            Log::info("Starting brand guideline extraction for customer {$customer->id}");

            // Step 1: Gather all knowledge base content
            $websiteContent = $customer->user->knowledgeBase()
                ->pluck('content')
                ->implode("\n\n---PAGE BREAK---\n\n");

            if (empty($websiteContent)) {
                Log::warning("No knowledge base content found for customer {$customer->id}");
                return null;
            }

            // Step 2: Scrape and analyze homepage for visual elements
            $visualAnalysis = $this->analyzeVisualStyle($customer->website_url);

            // Step 3: Build extraction prompt
            $prompt = (new BrandGuidelineExtractionPrompt(
                $websiteContent,
                $visualAnalysis,
                $customer->industry ?? 'general'
            ))->getPrompt();

            Log::info("Calling Gemini for brand guideline extraction", [
                'customer_id' => $customer->id,
                'content_length' => strlen($websiteContent),
            ]);

            // Step 4: Call Gemini with extended thinking for deep analysis
            $response = $this->geminiService->generateContent('gemini-2.5-pro', $prompt, [
                'thinkingConfig' => [
                    'includeThoughts' => true,
                    'thinkingBudget' => 3000
                ]
            ]);

            if (!$response || !isset($response['text'])) {
                Log::error("Failed to generate brand guidelines from Gemini", [
                    'customer_id' => $customer->id,
                ]);
                return null;
            }

            // Step 5: Parse and validate response
            $cleanedJson = $this->cleanJsonResponse($response['text']);
            $guidelines = json_decode($cleanedJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to parse brand guidelines JSON", [
                    'customer_id' => $customer->id,
                    'error' => json_last_error_msg(),
                    'response_preview' => substr($response['text'], 0, 500),
                ]);
                return null;
            }

            // Step 6: Validate required fields
            if (!$this->validateGuidelines($guidelines)) {
                Log::error("Brand guidelines missing required fields", [
                    'customer_id' => $customer->id,
                    'guidelines' => $guidelines,
                ]);
                return null;
            }

            // Step 7: Store brand guidelines
            $brandGuideline = BrandGuideline::updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'brand_voice' => $guidelines['brand_voice'],
                    'tone_attributes' => $guidelines['tone_attributes'],
                    'writing_patterns' => $guidelines['writing_patterns'] ?? null,
                    'color_palette' => $guidelines['color_palette'],
                    'typography' => $guidelines['typography'],
                    'visual_style' => $guidelines['visual_style'],
                    'messaging_themes' => $guidelines['messaging_themes'],
                    'unique_selling_propositions' => $guidelines['unique_selling_propositions'],
                    'target_audience' => $guidelines['target_audience'],
                    'competitor_differentiation' => $guidelines['competitor_differentiation'] ?? null,
                    'brand_personality' => $guidelines['brand_personality'],
                    'do_not_use' => $guidelines['do_not_use'] ?? [],
                    'extraction_quality_score' => $guidelines['extraction_quality_score'] ?? 50,
                    'extracted_at' => now(),
                ]
            );

            Log::info("Successfully extracted brand guidelines", [
                'customer_id' => $customer->id,
                'brand_guideline_id' => $brandGuideline->id,
                'quality_score' => $brandGuideline->extraction_quality_score,
            ]);

            return $brandGuideline;

        } catch (\Exception $e) {
            Log::error("Error extracting brand guidelines", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Analyze visual style from homepage HTML
     */
    private function analyzeVisualStyle(string $websiteUrl): array
    {
        try {
            Log::info("Analyzing visual style for: {$websiteUrl}");

            // Try Browsershot first for JavaScript-rendered sites
            try {
                $html = Browsershot::url($websiteUrl)
                    ->waitUntilNetworkIdle()
                    ->timeout(30)
                    ->bodyHtml();
            } catch (\Exception $e) {
                Log::warning("Browsershot failed, falling back to HTTP", [
                    'error' => $e->getMessage(),
                ]);
                // Fallback to simple HTTP request
                $response = Http::timeout(15)->get($websiteUrl);
                $html = $response->successful() ? $response->body() : '';
            }

            if (empty($html)) {
                Log::warning("Failed to fetch HTML for visual analysis");
                return $this->getDefaultVisualAnalysis();
            }

            return [
                'primary_colors' => $this->extractColors($html),
                'fonts' => $this->extractFonts($html),
                'image_style' => $this->detectImageStyle($html),
                'layout_style' => $this->detectLayoutStyle($html),
            ];

        } catch (\Exception $e) {
            Log::error("Error analyzing visual style: " . $e->getMessage());
            return $this->getDefaultVisualAnalysis();
        }
    }

    /**
     * Extract color palette from HTML/CSS
     */
    private function extractColors(string $html): array
    {
        $colors = [];
        
        // Extract hex colors from inline styles and style tags
        preg_match_all('/#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/', $html, $matches);
        
        if (!empty($matches[0])) {
            // Normalize 3-digit hex to 6-digit
            $hexColors = array_map(function($color) {
                $color = strtoupper(ltrim($color, '#'));
                if (strlen($color) === 3) {
                    $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
                }
                return '#' . $color;
            }, $matches[0]);
            
            // Count occurrences and get most common colors
            $colorCounts = array_count_values($hexColors);
            arsort($colorCounts);
            
            // Filter out near-white and near-black (usually backgrounds)
            $filteredColors = array_filter(array_keys($colorCounts), function($color) {
                // Skip #FFFFFF, #000000, and very close variants
                return !in_array($color, ['#FFFFFF', '#000000', '#FAFAFA', '#F5F5F5', '#111111']);
            });
            
            $colors = array_slice($filteredColors, 0, 6); // Top 6 colors
        }
        
        return !empty($colors) ? $colors : ['#0066CC', '#333333']; // Default fallback
    }

    /**
     * Extract font families from HTML/CSS
     */
    private function extractFonts(string $html): array
    {
        $fonts = [];
        
        // Look for font-family declarations
        preg_match_all('/font-family:\s*([^;}"]+)/i', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $fontDeclaration) {
                // Split by comma and clean up
                $fontList = explode(',', $fontDeclaration);
                foreach ($fontList as $font) {
                    $font = trim($font, " '\"");
                    // Skip generic font families
                    if (!in_array(strtolower($font), ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'])) {
                        $fonts[] = $font;
                    }
                }
            }
            
            // Get unique fonts
            $fonts = array_unique($fonts);
            $fonts = array_slice($fonts, 0, 5); // Top 5 fonts
        }
        
        return !empty($fonts) ? $fonts : ['Arial', 'Helvetica']; // Default fallback
    }

    /**
     * Detect image style (photography, illustrations, etc.)
     */
    private function detectImageStyle(string $html): string
    {
        // Look for image file extensions and SVG usage
        $jpgCount = substr_count(strtolower($html), '.jpg') + substr_count(strtolower($html), '.jpeg');
        $pngCount = substr_count(strtolower($html), '.png');
        $svgCount = substr_count(strtolower($html), '.svg') + substr_count(strtolower($html), '<svg');
        $gifCount = substr_count(strtolower($html), '.gif');
        
        // Determine dominant image type
        if ($svgCount > ($jpgCount + $pngCount)) {
            return 'illustrations and icons';
        } elseif ($jpgCount > $pngCount * 2) {
            return 'photography-heavy';
        } elseif ($gifCount > 5) {
            return 'animated and dynamic';
        } else {
            return 'mixed photography and graphics';
        }
    }

    /**
     * Detect layout style
     */
    private function detectLayoutStyle(string $html): string
    {
        // Simple heuristic based on common patterns
        if (stripos($html, 'grid') !== false || stripos($html, 'display: grid') !== false) {
            return 'grid-based layout';
        } elseif (stripos($html, 'flex') !== false || stripos($html, 'display: flex') !== false) {
            return 'flexible, modern layout';
        } else {
            return 'traditional layout';
        }
    }

    /**
     * Get default visual analysis when scraping fails
     */
    private function getDefaultVisualAnalysis(): array
    {
        return [
            'primary_colors' => ['#0066CC', '#333333'],
            'fonts' => ['Arial', 'Helvetica'],
            'image_style' => 'mixed content',
            'layout_style' => 'modern',
        ];
    }

    /**
     * Clean JSON response from AI (remove markdown fences, etc.)
     */
    private function cleanJsonResponse(string $text): string
    {
        // Remove markdown code fences
        $cleaned = preg_replace('/^```json\s*|\s*```$/m', '', $text);
        
        // Trim whitespace
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }

    /**
     * Validate that guidelines have all required fields
     */
    private function validateGuidelines(array $guidelines): bool
    {
        $requiredFields = [
            'brand_voice',
            'tone_attributes',
            'color_palette',
            'typography',
            'visual_style',
            'messaging_themes',
            'unique_selling_propositions',
            'target_audience',
            'brand_personality',
        ];

        foreach ($requiredFields as $field) {
            if (!isset($guidelines[$field]) || empty($guidelines[$field])) {
                Log::warning("Missing required field in brand guidelines: {$field}");
                return false;
            }
        }

        return true;
    }
}
```

### 5. ExtractBrandGuidelines Job

**File:** `app/Jobs/ExtractBrandGuidelines.php`

```php
<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractBrandGuidelines implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Customer $customer
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BrandGuidelineExtractorService $extractor): void
    {
        Log::info("ExtractBrandGuidelines job started for customer {$this->customer->id}");

        try {
            $brandGuideline = $extractor->extractGuidelines($this->customer);

            if ($brandGuideline) {
                Log::info("Brand guidelines extracted successfully", [
                    'customer_id' => $this->customer->id,
                    'guideline_id' => $brandGuideline->id,
                    'quality_score' => $brandGuideline->extraction_quality_score,
                ]);
            } else {
                Log::warning("Brand guideline extraction returned null", [
                    'customer_id' => $this->customer->id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("ExtractBrandGuidelines job failed", [
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
            ]);
            
            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ExtractBrandGuidelines job failed permanently", [
            'customer_id' => $this->customer->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

## Integration Points

### 1. Trigger After Knowledge Base Population

**File:** `app/Observers/CustomerObserver.php` (or similar)

```php
<?php

namespace App\Observers;

use App\Jobs\ExtractBrandGuidelines;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class CustomerObserver
{
    /**
     * Handle the Customer "created" event.
     */
    public function created(Customer $customer): void
    {
        // Trigger brand guideline extraction after knowledge base is populated
        // This will be dispatched after website scraping completes
        Log::info("Customer created, will extract brand guidelines after knowledge base population", [
            'customer_id' => $customer->id,
        ]);
    }
}
```

### 2. Trigger After Knowledge Base Completion

**File:** `app/Jobs/ScrapeCustomerWebsite.php` (add to end of handle method)

```php
// At the end of the ScrapeCustomerWebsite job:
public function handle(): void
{
    // ... existing scraping logic ...
    
    // After all pages are scraped and knowledge base is populated
    if ($successfulScrapesCount > 0) {
        Log::info("Website scraping completed, dispatching brand guideline extraction", [
            'customer_id' => $customer->id,
        ]);
        
        // Dispatch brand guideline extraction job
        dispatch(new ExtractBrandGuidelines($customer))->delay(now()->addMinutes(2));
    }
}
```

### 3. Manual Refresh Command

**File:** `app/Console/Commands/ExtractBrandGuidelinesCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Jobs\ExtractBrandGuidelines;
use App\Models\Customer;
use Illuminate\Console\Command;

class ExtractBrandGuidelinesCommand extends Command
{
    protected $signature = 'brand:extract {customer_id? : The customer ID to extract guidelines for}';
    protected $description = 'Extract brand guidelines for a customer or all customers';

    public function handle(): int
    {
        if ($customerId = $this->argument('customer_id')) {
            $customer = Customer::find($customerId);
            
            if (!$customer) {
                $this->error("Customer {$customerId} not found");
                return 1;
            }
            
            $this->info("Extracting brand guidelines for customer {$customer->id}...");
            dispatch(new ExtractBrandGuidelines($customer));
            $this->info("Job dispatched successfully");
            
        } else {
            $this->info("Extracting brand guidelines for all customers...");
            
            Customer::whereNotNull('website_url')
                ->chunk(10, function ($customers) {
                    foreach ($customers as $customer) {
                        dispatch(new ExtractBrandGuidelines($customer));
                    }
                });
            
            $this->info("Jobs dispatched for all customers");
        }

        return 0;
    }
}
```

### 4. Update Prompt Classes

All prompt classes should be updated to accept and use `BrandGuideline`. Example:

**File:** `app/Prompts/AdCopyPrompt.php` (updated)

```php
<?php

namespace App\Prompts;

use App\Models\BrandGuideline;
use Illuminate\Support\Facades\Log;

class AdCopyPrompt
{
    private string $strategyContent;
    private string $platform;
    private ?array $rules;
    private ?array $feedback;
    private ?BrandGuideline $brandGuidelines;

    public function __construct(
        string $strategyContent,
        string $platform,
        ?array $rules = null,
        ?array $feedback = null,
        ?BrandGuideline $brandGuidelines = null
    ) {
        $this->strategyContent = $strategyContent;
        $this->platform = $platform;
        $this->rules = $rules ?? [];
        $this->feedback = $feedback ?? [];
        $this->brandGuidelines = $brandGuidelines;
    }

    public function getPrompt(): string
    {
        $rulesString = !empty($this->rules) ? json_encode($this->rules, JSON_PRETTY_PRINT) : 'No specific rules provided.';

        // Include brand guidelines if available
        $brandContext = $this->brandGuidelines
            ? $this->formatBrandContext()
            : "**BRAND GUIDELINES:** Not available. Use professional, engaging tone suitable for {$this->platform}.";

        $basePrompt = "You are an expert copywriter specializing in {$this->platform} advertising.\n\n" .
                      $brandContext . "\n\n" .
                      "--- PLATFORM RULES ---\n" .
                      $rulesString . "\n\n" .
                      "--- RESPONSE FORMAT ---\n" .
                      "Return the output as a JSON object with two keys: 'headlines' (an array of strings) and 'descriptions' (an array of strings). " .
                      "Do NOT include any conversational text, explanations, or additional formatting outside the JSON object. " .
                      "Example: {\"headlines\": [\"Headline 1\", \"Headline 2\"], \"descriptions\": [\"Description 1.\", \"Description 2.\"]}\n\n" .
                      "--- MARKETING STRATEGY ---\n{$this->strategyContent}";

        if (!empty($this->feedback)) {
            $feedbackString = json_encode($this->feedback, JSON_PRETTY_PRINT);
            $basePrompt .= "\n\n--- CRITICAL CORRECTIONS REQUIRED ---\n" .
                           "The previous ad copy you generated was REJECTED because it violated the platform's rules. You MUST fix the following errors:\n" .
                           $feedbackString . "\n\n" .
                           "Generate a completely new and valid set of ad copy that strictly adheres to all rules and corrects these specific errors.";
        }

        Log::info("Generated AdCopyPrompt with brand guidelines.", [
            'has_brand_guidelines' => !is_null($this->brandGuidelines),
            'platform' => $this->platform,
        ]);

        return $basePrompt;
    }

    private function formatBrandContext(): string
    {
        return <<<BRAND
--- BRAND GUIDELINES ---

{$this->brandGuidelines->getFormattedBrandVoice()}

{$this->brandGuidelines->getFormattedTargetAudience()}

**UNIQUE SELLING PROPOSITIONS:**
{$this->brandGuidelines->getFormattedUSPs()}

**MESSAGING THEMES:**
{$this->formatMessagingThemes()}

{$this->formatConstraints()}

--- END BRAND GUIDELINES ---
BRAND;
    }

    private function formatMessagingThemes(): string
    {
        return implode("\n", array_map(
            fn($theme) => "- {$theme}",
            $this->brandGuidelines->messaging_themes
        ));
    }

    private function formatConstraints(): string
    {
        if (empty($this->brandGuidelines->do_not_use)) {
            return '';
        }

        return "**DO NOT USE:**\n" . implode("\n", array_map(
            fn($item) => "- {$item}",
            $this->brandGuidelines->do_not_use
        ));
    }
}
```

### 5. Update Job Classes to Pass Brand Guidelines

**File:** `app/Jobs/GenerateAdCopy.php` (updated)

```php
public function handle(): void
{
    try {
        Log::info("GenerateAdCopy job started for Campaign {$this->campaign->id}.");
        
        // Initialize services
        $geminiService = new GeminiService();
        $adminMonitorService = new AdminMonitorService($geminiService);

        // Get brand guidelines for this customer
        $brandGuidelines = $this->campaign->user->customer->brandGuideline;
        
        if (!$brandGuidelines) {
            Log::warning("No brand guidelines found for customer {$this->campaign->user->customer->id}");
        }

        $strategyContent = $this->strategy->ad_copy_strategy;
        $maxAttempts = 3;
        $approvedAdCopyData = null;
        $lastFeedback = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            Log::info("Attempting to generate and review ad copy (Attempt {$attempt}/{$maxAttempts})");

            $rules = AdminMonitorService::getRulesForPlatform($this->platform);
            
            // Pass brand guidelines to prompt
            $adCopyPrompt = (new AdCopyPrompt(
                $strategyContent,
                $this->platform,
                $rules,
                $lastFeedback,
                $brandGuidelines // NEW
            ))->getPrompt();
            
            // ... rest of the method ...
        }
    }
}
```

---

## Testing Strategy

### Unit Tests

**File:** `tests/Unit/BrandGuidelineExtractorServiceTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\User;
use App\Services\BrandGuidelineExtractorService;
use App\Services\GeminiService;
use Tests\TestCase;

class BrandGuidelineExtractorServiceTest extends TestCase
{
    public function test_extracts_guidelines_successfully()
    {
        // Create test customer with knowledge base
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['user_id' => $user->id]);
        
        $user->knowledgeBase()->create([
            'content' => 'Sample website content with brand voice...',
        ]);

        // Mock Gemini service
        $geminiMock = $this->mock(GeminiService::class);
        $geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'brand_voice' => ['primary_tone' => 'professional', 'description' => 'Test'],
                    'tone_attributes' => ['professional', 'friendly'],
                    // ... other required fields
                ])
            ]);

        $service = new BrandGuidelineExtractorService($geminiMock);
        $guideline = $service->extractGuidelines($customer);

        $this->assertNotNull($guideline);
        $this->assertEquals($customer->id, $guideline->customer_id);
    }

    public function test_handles_missing_knowledge_base()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['user_id' => $user->id]);

        $geminiMock = $this->mock(GeminiService::class);
        $service = new BrandGuidelineExtractorService($geminiMock);
        
        $guideline = $service->extractGuidelines($customer);

        $this->assertNull($guideline);
    }
}
```

### Integration Tests

**File:** `tests/Feature/BrandGuidelineExtractionTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\ExtractBrandGuidelines;
use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrandGuidelineExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_extraction_job_is_dispatched_after_scraping()
    {
        Queue::fake();

        $user = User::factory()->create();
        $customer = Customer::factory()->create(['user_id' => $user->id]);

        // Simulate knowledge base population
        $user->knowledgeBase()->create(['content' => 'Test content']);

        // Dispatch extraction job
        ExtractBrandGuidelines::dispatch($customer);

        Queue::assertPushed(ExtractBrandGuidelines::class);
    }

    public function test_brand_guidelines_are_stored_correctly()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['user_id' => $user->id]);

        $guideline = BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => ['primary_tone' => 'professional'],
            'tone_attributes' => ['professional'],
            // ... other fields
            'extracted_at' => now(),
        ]);

        $this->assertDatabaseHas('brand_guidelines', [
            'customer_id' => $customer->id,
        ]);

        $this->assertEquals('professional', $guideline->brand_voice['primary_tone']);
    }
}
```

---

## Rollout Plan

### Phase 1: Development & Testing (Days 1-7)

- [ ] Day 1-2: Implement core infrastructure (migration, model, service, prompt)
- [ ] Day 3: Implement visual analysis features
- [ ] Day 4-5: Integration with existing jobs and prompts
- [ ] Day 6: Testing with real websites
- [ ] Day 7: Bug fixes and refinements

### Phase 2: Pilot (Days 8-10)

- [ ] Run extraction for 10 test customers
- [ ] Review quality scores and extraction accuracy
- [ ] Manual validation of extracted guidelines
- [ ] Tune prompt based on results
- [ ] Adjust quality thresholds

### Phase 3: Full Rollout (Days 11-14)

- [ ] Deploy to production
- [ ] Run extraction for all existing customers (background job)
- [ ] Monitor error rates and quality scores
- [ ] Set up scheduled refresh (weekly)
- [ ] Enable UI for viewing/editing guidelines

### Phase 4: Monitoring & Optimization (Ongoing)

- [ ] Track content approval rates before/after guidelines
- [ ] Monitor extraction quality scores
- [ ] Gather user feedback on guideline accuracy
- [ ] Iterate on prompt based on learnings
- [ ] A/B test prompt variations

---

## Success Metrics

### Extraction Quality
- **Target:** 90%+ of extractions succeed (non-null result)
- **Target:** Average quality score >75
- **Target:** <10% manual override rate

### Content Quality Impact
- **Target:** +20% increase in content approval scores
- **Target:** -30% decrease in content regeneration iterations
- **Target:** +15% increase in campaign CTR (long-term)

### System Health
- **Target:** <5% extraction job failure rate
- **Target:** Extraction completes in <2 minutes average
- **Target:** 100% of new customers get guidelines within 1 hour of signup

---

## Next Steps

1. **Create migration and run it:**
   ```bash
   php artisan make:migration create_brand_guidelines_table
   php artisan migrate
   ```

2. **Create model:**
   ```bash
   php artisan make:model BrandGuideline
   ```

3. **Create service, prompt, and job files**

4. **Test with a single customer:**
   ```bash
   php artisan brand:extract 1
   ```

5. **Review extracted guidelines and iterate on prompt**

6. **Integrate into all prompt classes**

7. **Deploy to production**

---

**Document Version:** 1.0  
**Last Updated:** November 18, 2025  
**Implementation Status:** 🟡 Ready to Start
