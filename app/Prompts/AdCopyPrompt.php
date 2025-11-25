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
    private ?array $productContext;

    public function __construct(
        string $strategyContent,
        string $platform,
        ?array $rules = null,
        ?array $feedback = null,
        ?BrandGuideline $brandGuidelines = null,
        ?array $productContext = null
    ) {
        $this->strategyContent = $strategyContent;
        $this->platform = $platform;
        $this->rules = $rules ?? [];
        $this->feedback = $feedback ?? [];
        $this->brandGuidelines = $brandGuidelines;
        $this->productContext = $productContext;
    }

    public function getPrompt(): string
    {
        $rulesString = !empty($this->rules) ? json_encode($this->rules, JSON_PRETTY_PRINT) : 'No specific rules provided.';

        // Include brand guidelines if available
        $brandContext = $this->brandGuidelines
            ? $this->formatBrandContext()
            : "**BRAND GUIDELINES:** Not available. Use professional, engaging tone suitable for {$this->platform}.";

        // Include product context if available
        $productContextString = '';
        if (!empty($this->productContext)) {
            $productContextString = "\n\n--- SELECTED PRODUCTS ---\n" .
                "The user has selected specific products to advertise. You MUST incorporate their details (Price, Title, Features) into the ad copy where appropriate.\n" .
                json_encode($this->productContext, JSON_PRETTY_PRINT);
        }

        $basePrompt = "You are an expert copywriter specializing in {$this->platform} advertising.\n\n" .
                      $brandContext . "\n\n" .
                      "--- PLATFORM RULES ---\n" .
                      $rulesString . 
                      $productContextString . "\n\n" .
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