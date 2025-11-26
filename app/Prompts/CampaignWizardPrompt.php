<?php

namespace App\Prompts;

use App\Models\BrandGuideline;

/**
 * CampaignWizardPrompt constructs the system prompt for the AI-assisted 
 * campaign creation wizard. It leverages existing brand guidelines to 
 * pre-populate voice/tone and other brand-specific information.
 */
class CampaignWizardPrompt
{
    /**
     * Build the system instruction for the campaign wizard AI assistant.
     *
     * @param BrandGuideline|null $brandGuidelines The customer's brand guidelines if available.
     * @return string The system instruction.
     */
    public static function build(?BrandGuideline $brandGuidelines = null): string
    {
        $brandContext = self::formatBrandContext($brandGuidelines);
        
        return <<<PROMPT
You are a helpful AI assistant specializing in digital marketing campaign creation. Your job is to help users create effective advertising campaigns by gathering the necessary information through a friendly conversation.

{$brandContext}

## Information to Collect

You need to collect the following information for a campaign:

### Required:
1. **Campaign Name** - A memorable name for the campaign
2. **Reason/Objective** - Why they're running this campaign (e.g., product launch, seasonal sale, brand awareness)
3. **Goals** - What they want to achieve (e.g., increase sales by 20%, get 1000 new leads)
4. **Target Market** - Who they want to reach (demographics, interests, behaviors)
5. **Total Budget** - How much they want to spend overall (in dollars)
6. **Start Date** - When the campaign should begin
7. **End Date** - When the campaign should end
8. **Primary KPI** - The main metric to track (e.g., conversions, clicks, impressions, ROAS)

### Optional:
9. **Product Focus** - Specific products or services to highlight
10. **Exclusions** - Anything to avoid in the campaign

## Guidelines

- Be conversational and friendly, ask follow-up questions naturally
- Don't ask for all information at once - guide them through step by step
- Provide suggestions and examples when helpful
- Once you have enough information, summarize what you've collected
- If the user's input is unclear, ask for clarification
- For dates, convert natural language (e.g., "next month", "2 weeks from now") to YYYY-MM-DD format
- For budget, accept various formats ($1000, 1k, etc.) and normalize to a number

## Output Format

When you have collected sufficient information for a campaign (at minimum: name, reason, goals, target_market, and budget), include a JSON block in your response with the extracted data:

\`\`\`campaign_data
{
  "name": "Campaign Name",
  "reason": "Campaign reason/objective",
  "goals": "Campaign goals",
  "target_market": "Target audience description",
  "voice": "Brand voice/tone from guidelines or user input",
  "total_budget": "1000",
  "start_date": "2024-01-15",
  "end_date": "2024-02-15",
  "primary_kpi": "conversions",
  "product_focus": "Optional product focus",
  "exclusions": "Optional exclusions"
}
\`\`\`

Important: Only include the campaign_data block when you have enough information. The "voice" field should use the brand guidelines voice/tone if available, or ask the user if not.
PROMPT;
    }

    /**
     * Format brand context from brand guidelines.
     *
     * @param BrandGuideline|null $brandGuidelines
     * @return string
     */
    private static function formatBrandContext(?BrandGuideline $brandGuidelines): string
    {
        if (!$brandGuidelines) {
            return <<<CONTEXT
## Brand Context
No brand guidelines are available for this customer. You should ask about:
- Their brand's voice and tone (professional, friendly, casual, etc.)
- Any specific messaging guidelines they want to follow
CONTEXT;
        }

        $context = "## Brand Context (Pre-loaded from Brand Guidelines)\n\n";
        $context .= "The following brand information has already been captured. Use this to inform the campaign and don't ask the user to repeat this information:\n\n";

        if ($brandGuidelines->company_name) {
            $name = self::formatValue($brandGuidelines->company_name);
            $context .= "**Company Name:** {$name}\n";
        }

        if ($brandGuidelines->industry) {
            $industry = self::formatValue($brandGuidelines->industry);
            $context .= "**Industry:** {$industry}\n";
        }

        if ($brandGuidelines->brand_voice) {
            $voice = self::formatValue($brandGuidelines->brand_voice);
            $context .= "**Brand Voice:** {$voice}\n";
        }

        if ($brandGuidelines->tone_attributes) {
            $tones = self::formatValue($brandGuidelines->tone_attributes);
            $context .= "**Tone Attributes:** {$tones}\n";
        }

        if ($brandGuidelines->target_audience) {
            $audience = self::formatValue($brandGuidelines->target_audience);
            $context .= "**Target Audience:** {$audience}\n";
        }

        if ($brandGuidelines->unique_selling_points) {
            $usps = self::formatValue($brandGuidelines->unique_selling_points);
            $context .= "**Unique Selling Points:** {$usps}\n";
        }

        if ($brandGuidelines->key_messages) {
            $messages = self::formatValue($brandGuidelines->key_messages, '; ');
            $context .= "**Key Messages:** {$messages}\n";
        }

        if ($brandGuidelines->words_to_avoid) {
            $avoid = self::formatValue($brandGuidelines->words_to_avoid);
            $context .= "**Words/Phrases to Avoid:** {$avoid}\n";
        }

        $context .= "\nUse this brand information to:\n";
        $context .= "- Pre-fill the 'voice' field with the brand voice/tone\n";
        $context .= "- Suggest target markets based on the target audience\n";
        $context .= "- Reference USPs when discussing campaign goals\n";
        $context .= "- Ensure exclusions include any words/phrases to avoid\n";

        return $context;
    }

    /**
     * Get the default voice value from brand guidelines.
     *
     * @param BrandGuideline|null $brandGuidelines
     * @return string|null
     */
    public static function getDefaultVoice(?BrandGuideline $brandGuidelines): ?string
    {
        if (!$brandGuidelines) {
            return null;
        }

        $voice = $brandGuidelines->brand_voice ?? '';
        
        if ($brandGuidelines->tone_attributes) {
            $tones = self::formatValue($brandGuidelines->tone_attributes);
            $voice .= $voice ? " - {$tones}" : $tones;
        }

        return $voice ?: null;
    }
    
    /**
     * Format a value that might be an array, object, or string into a string.
     *
     * @param mixed $value The value to format
     * @param string $separator The separator to use when joining array elements
     * @return string
     */
    private static function formatValue($value, string $separator = ', '): string
    {
        if (is_null($value)) {
            return '';
        }
        
        if (is_string($value)) {
            return $value;
        }
        
        if (is_array($value)) {
            // Handle nested arrays by flattening
            $flattened = [];
            array_walk_recursive($value, function($item) use (&$flattened) {
                if (is_string($item) || is_numeric($item)) {
                    $flattened[] = $item;
                }
            });
            return implode($separator, $flattened);
        }
        
        if (is_object($value)) {
            // Try to convert to array first
            return self::formatValue((array) $value, $separator);
        }
        
        // Fallback for other types
        return (string) $value;
    }
}
