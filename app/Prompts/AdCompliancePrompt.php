<?php

namespace App\Prompts;

class AdCompliancePrompt
{
    /**
     * Generate a prompt to rewrite ad copy for policy compliance.
     *
     * @param array $originalAd The original ad data (headlines, descriptions, or primary_text, headline for FB)
     * @param string $policyViolation The policy topic/reason for disapproval
     * @return string The prompt for the AI
     */
    public static function generate(array $originalAd, string $policyViolation): string
    {
        $platform = $originalAd['platform'] ?? 'google_ads';
        
        if ($platform === 'facebook_ads') {
            return self::generateFacebookPrompt($originalAd, $policyViolation);
        }
        
        return self::generateGooglePrompt($originalAd, $policyViolation);
    }
    
    /**
     * Generate prompt for Google Ads compliance.
     */
    protected static function generateGooglePrompt(array $originalAd, string $policyViolation): string
    {
        $headlinesJson = json_encode($originalAd['headlines'] ?? [], JSON_PRETTY_PRINT);
        $descriptionsJson = json_encode($originalAd['descriptions'] ?? [], JSON_PRETTY_PRINT);

        return <<<PROMPT
You are an expert Google Ads policy compliance specialist.
An ad was disapproved for the following policy violation: **{$policyViolation}**

Original Ad Copy:
Headlines: $headlinesJson
Descriptions: $descriptionsJson

Your task is to rewrite this ad to be policy-compliant while preserving the marketing message.

Common Google Ads policy violations and fixes:
- **Misleading claims**: Remove superlatives like "best", "guaranteed", "100%". Use softer language.
- **Trademark issues**: Replace brand names with generic terms or add the registered symbol.
- **Unsubstantiated claims**: Add qualifiers like "may", "can help", "designed to".
- **Punctuation abuse**: Remove excessive punctuation (!!!, ???, etc.).
- **Capitalization**: Don't use ALL CAPS for emphasis.
- **Adult content**: Use appropriate, family-friendly language.
- **Healthcare claims**: Add disclaimers, avoid claiming cures.
- **Spacing/symbols**: Remove gimmicky spacing or special characters used to draw attention.
- **Malicious software**: Ensure URLs and content don't trigger malware flags.

Return your response in this exact JSON format:
{
    "headlines": [
        "Compliant Headline 1 (max 30 chars)",
        "Compliant Headline 2 (max 30 chars)",
        "Compliant Headline 3 (max 30 chars)"
    ],
    "descriptions": [
        "Compliant description 1 (max 90 chars)",
        "Compliant description 2 (max 90 chars)"
    ],
    "changes_made": "Brief explanation of what was changed to achieve compliance",
    "confidence": "HIGH|MEDIUM|LOW - confidence that the changes will resolve the policy issue"
}

Important:
- Headlines must be 30 characters or less
- Descriptions must be 90 characters or less
- Preserve the core marketing message
- Make the minimum changes necessary for compliance
PROMPT;
    }
    
    /**
     * Generate prompt for Facebook/Meta Ads compliance.
     */
    protected static function generateFacebookPrompt(array $originalAd, string $policyViolation): string
    {
        $headline = $originalAd['headline'] ?? '';
        $primaryText = $originalAd['primary_text'] ?? '';
        $description = $originalAd['description'] ?? '';
        
        return <<<PROMPT
You are an expert Facebook/Meta Ads policy compliance specialist.
An ad was disapproved for the following policy violation: **{$policyViolation}**

Original Ad Copy:
Headline: {$headline}
Primary Text: {$primaryText}
Description: {$description}

Your task is to rewrite this ad to be policy-compliant while preserving the marketing message.

Common Facebook Ads policy violations and fixes:
- **Personal attributes**: Avoid implying you know personal characteristics ("You're overweight", "Struggling with debt?"). Use general language instead.
- **Misleading claims**: Remove exaggerated results, "guaranteed" outcomes, unrealistic timelines.
- **Before/after images**: Avoid implying dramatic transformations.
- **Sensational content**: Remove shocking, exaggerated, or click-bait language.
- **Social issues**: Be careful with content related to politics, social causes.
- **Cryptocurrency/Financial**: Include proper disclaimers, avoid promising returns.
- **Health claims**: Add disclaimers, don't claim cures, be careful with weight loss.
- **Discriminatory content**: Ensure ad doesn't discriminate based on protected characteristics.
- **Adult content**: Remove suggestive language, use appropriate imagery descriptions.
- **Third-party rights**: Don't use other brands' names without permission.
- **Grammar/spelling**: Use proper language, avoid excessive emojis or symbols.
- **Engagement bait**: Avoid asking for likes, shares, or comments explicitly.

Return your response in this exact JSON format:
{
    "headline": "Compliant headline (max 40 chars recommended)",
    "primary_text": "Compliant primary text (max 125 chars for best display)",
    "description": "Compliant description (max 30 chars recommended)",
    "changes_made": "Brief explanation of what was changed to achieve compliance",
    "confidence": "HIGH|MEDIUM|LOW - confidence that the changes will resolve the policy issue",
    "additional_notes": "Any additional recommendations for avoiding future violations"
}

Important:
- Headline: 40 characters recommended (truncates at 25 on some placements)
- Primary Text: 125 characters recommended (shows full on most placements)
- Description: 30 characters recommended (link description)
- Preserve the core marketing message
- Make the minimum changes necessary for compliance
- Consider Facebook's stricter personal attributes policy
PROMPT;
    }
}
