<?php

namespace App\Prompts;

class AdCompliancePrompt
{
    /**
     * Generate a prompt to rewrite ad copy for policy compliance.
     *
     * @param array $originalAd The original ad data (headlines, descriptions)
     * @param string $policyViolation The policy topic/reason for disapproval
     * @return string The prompt for the AI
     */
    public static function generate(array $originalAd, string $policyViolation): string
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

Common policy violations and fixes:
- **Misleading claims**: Remove superlatives like "best", "guaranteed", "100%". Use softer language.
- **Trademark issues**: Replace brand names with generic terms.
- **Unsubstantiated claims**: Add qualifiers like "may", "can help", "designed to".
- **Punctuation abuse**: Remove excessive punctuation (!!!, ???, etc.).
- **Capitalization**: Don't use ALL CAPS for emphasis.
- **Adult content**: Use appropriate, family-friendly language.
- **Healthcare claims**: Add disclaimers, avoid claiming cures.

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
    "changes_made": "Brief explanation of what was changed to achieve compliance"
}

Important:
- Headlines must be 30 characters or less
- Descriptions must be 90 characters or less
- Preserve the core marketing message
- Make the minimum changes necessary for compliance
PROMPT;
    }
}
