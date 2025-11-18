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
    "primary_tone": "professional",
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
    "sentence_length": "varied",
    "paragraph_style": "Description of typical paragraph structure and length",
    "uses_questions": true,
    "uses_statistics": true,
    "uses_testimonials": true,
    "uses_storytelling": false,
    "call_to_action_style": "Description of how they typically phrase CTAs",
    "punctuation_style": "formal",
    "emoji_usage": "none"
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
    "letter_spacing": "normal"
  },
  "visual_style": {
    "overall_aesthetic": "modern",
    "imagery_style": "photography",
    "description": "Detailed description of their visual approach and what makes it distinctive",
    "color_treatment": "vibrant",
    "layout_preference": "clean and spacious"
  },
  "messaging_themes": [
    "Primary theme they communicate about",
    "Secondary theme",
    "Tertiary theme"
  ],
  "unique_selling_propositions": [
    "First USP: What makes them unique",
    "Second USP: Another key differentiator",
    "Third USP: Additional competitive advantage"
  ],
  "target_audience": {
    "primary": "Detailed description of their primary target audience",
    "demographics": "Age range, income level, education, job titles, etc.",
    "psychographics": "Values, interests, lifestyle, aspirations, pain points",
    "pain_points": [
      "Key problem their audience faces",
      "Another pain point they address"
    ],
    "language_level": "professional",
    "familiarity_assumption": "intermediate"
  },
  "competitor_differentiation": [
    "How they position themselves differently from competitors",
    "Key competitive advantages they emphasize"
  ],
  "brand_personality": {
    "archetype": "Hero",
    "characteristics": [
      "Personality trait 1",
      "Personality trait 2",
      "Personality trait 3",
      "Personality trait 4"
    ],
    "if_brand_were_person": "1-2 sentence description of the brand as if it were a person"
  },
  "do_not_use": [
    "Words, phrases, or approaches they explicitly avoid",
    "Industry jargon they steer clear of"
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
