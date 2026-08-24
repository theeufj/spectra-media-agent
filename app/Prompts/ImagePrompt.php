<?php

namespace App\Prompts;

use App\Models\BrandGuideline;
use App\Models\Setting;

class ImagePrompt
{
    /**
     * Admin-editable override for the creative generation prompt. Blank or
     * unset means the built-in default below. Edited from Admin → Settings.
     */
    public const TEMPLATE_SETTING = 'image_prompt_template';

    private string $strategyContent;

    private ?BrandGuideline $brandGuidelines;

    private ?array $productContext;

    public function __construct(string $strategyContent, ?BrandGuideline $brandGuidelines = null, ?array $productContext = null)
    {
        $this->strategyContent = $strategyContent;
        $this->brandGuidelines = $brandGuidelines;
        $this->productContext = $productContext;
    }

    /**
     * The built-in prompt template. Placeholders are substituted per
     * generation: {{brand_context}} (colour palette + visual style from the
     * brand guidelines), {{product_context}} (product details when the
     * campaign sells specific products), {{creative_strategy}} (the
     * strategy's imagery brief).
     */
    public static function defaultTemplate(): string
    {
        return "Generate a high-quality, visually compelling marketing image that adheres to the following requirements:\n\n".
               "{{brand_context}}{{product_context}}\n\n".
               "**TECHNICAL SPECIFICATIONS:**\n".
               "- Style: Professional, modern, high-resolution\n".
               "- Format: Suitable for digital advertising\n".
               "- Aspect Ratio: 1:1 (Square) - 1024x1024 pixels\n".
               "- Composition: Clear focal point, mobile-friendly, high contrast\n\n".
               "**CREATIVE STRATEGY:**\n".
               "{{creative_strategy}}\n\n".
               "**IMPORTANT:**\n".
               "- Avoid text in the image (will be added separately)\n".
               "- Ensure cultural sensitivity and inclusivity\n".
               "- No stock photo clichés\n".
               '- Brand recognition should be implicit through style';
    }

    /**
     * The template actually in force: the admin override when one is set,
     * otherwise the default.
     */
    public static function activeTemplate(): string
    {
        $custom = trim((string) Setting::get(self::TEMPLATE_SETTING, ''));

        return $custom !== '' ? $custom : self::defaultTemplate();
    }

    public function getPrompt(): string
    {
        $brandContext = $this->brandGuidelines ? $this->formatBrandContext() : '';

        $productContextString = '';
        if (! empty($this->productContext)) {
            $productContextString = "\n\n**PRODUCT DETAILS:**\n".
                "The image MUST feature or relate to the following product(s):\n".
                json_encode($this->productContext, JSON_PRETTY_PRINT);
        }

        return strtr(self::activeTemplate(), [
            '{{brand_context}}' => $brandContext,
            '{{product_context}}' => $productContextString,
            '{{creative_strategy}}' => $this->strategyContent,
        ]);
    }

    private function formatBrandContext(): string
    {
        if (! $this->brandGuidelines) {
            return '';
        }

        $visualStyle = $this->brandGuidelines->visual_style;

        return "**BRAND STYLE GUIDELINES:**\n".
               $this->brandGuidelines->getFormattedColorPalette()."\n".
               "**Visual Style:** {$visualStyle['overall_aesthetic']}\n".
               "**Imagery Style:** {$visualStyle['imagery_style']}\n".
               "**Description:** {$visualStyle['description']}\n\n";
    }
}
