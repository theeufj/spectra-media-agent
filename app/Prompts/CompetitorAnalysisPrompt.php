<?php

namespace App\Prompts;

/**
 * CompetitorAnalysisPrompt
 * 
 * Generates prompts for deep analysis of competitor websites,
 * extracting messaging, value propositions, and counter-strategy recommendations.
 */
class CompetitorAnalysisPrompt
{
    /**
     * Generate the system instruction for competitor analysis.
     */
    public static function getSystemInstruction(): string
    {
        return <<<INSTRUCTION
You are an expert competitive intelligence analyst specializing in digital marketing and advertising strategy.

Your task is to analyze competitor websites and extract actionable intelligence that can be used to create superior advertising campaigns.

Focus on:
1. Messaging and positioning - What are they saying to customers?
2. Value propositions - What benefits do they highlight?
3. Pricing signals - Any visible pricing or positioning?
4. Target audience signals - Who are they targeting?
5. Differentiators - What makes them unique?

Provide strategic recommendations for how to compete against them in advertising.
INSTRUCTION;
    }

    /**
     * Build the competitor analysis prompt.
     *
     * @param string $competitorUrl The competitor's URL
     * @param string $competitorContent The scraped content from competitor website
     * @param string $ourBusinessContext Brief context about our business
     * @return string The formatted prompt
     */
    public static function build(
        string $competitorUrl,
        string $competitorContent,
        string $ourBusinessContext
    ): string {
        return <<<PROMPT
**COMPETITOR ANALYSIS REQUEST**

**Competitor Website:** {$competitorUrl}

**Competitor Website Content:**
{$competitorContent}

---

**Our Business Context:**
{$ourBusinessContext}

---

**ANALYSIS REQUIRED:**

Analyze this competitor's website and provide:

1. **Messaging Analysis**
   - Primary headline/tagline
   - Key messages they communicate
   - Emotional triggers they use
   - Call-to-action language

2. **Value Propositions**
   - Main benefits they highlight
   - Features they emphasize
   - Social proof elements (testimonials, case studies, numbers)

3. **Pricing Intelligence**
   - Any visible pricing
   - Pricing positioning (budget, mid-market, premium)
   - Free trial or freemium signals

4. **Target Audience Signals**
   - Who they appear to be targeting
   - Industry/vertical focus
   - Company size focus (SMB, mid-market, enterprise)

5. **Keywords & Themes**
   - Key terms they use repeatedly
   - SEO-focused keywords visible
   - Pain points they address

6. **Counter-Strategy Recommendations**
   - How to differentiate in ad copy
   - Messaging gaps we can exploit
   - Keywords to target against them
   - Positioning recommendations

**RESPONSE FORMAT (JSON):**
{
  "messaging": {
    "primary_headline": "Their main headline",
    "key_messages": ["message 1", "message 2"],
    "emotional_triggers": ["trigger 1", "trigger 2"],
    "cta_language": ["CTA examples"]
  },
  "value_propositions": {
    "main_benefits": ["benefit 1", "benefit 2"],
    "features_emphasized": ["feature 1", "feature 2"],
    "social_proof": {
      "testimonials": true/false,
      "case_studies": true/false,
      "stats_claims": ["stat 1", "stat 2"]
    }
  },
  "pricing": {
    "visible_pricing": true/false,
    "pricing_info": "What we found",
    "positioning": "budget|mid-market|premium|unclear"
  },
  "target_audience": {
    "primary_segment": "Who they target",
    "industry_focus": ["industry 1", "industry 2"],
    "company_size": "smb|mid-market|enterprise|all"
  },
  "keywords_themes": {
    "primary_keywords": ["keyword 1", "keyword 2"],
    "pain_points_addressed": ["pain 1", "pain 2"]
  },
  "counter_strategy": {
    "differentiation_angles": ["angle 1", "angle 2"],
    "messaging_gaps": ["gap 1", "gap 2"],
    "keywords_to_target": ["keyword 1", "keyword 2"],
    "ad_copy_recommendations": ["recommendation 1", "recommendation 2"]
  }
}
PROMPT;
    }
}
