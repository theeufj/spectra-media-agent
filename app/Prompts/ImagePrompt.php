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

    private string $adText;

    public function __construct(string $strategyContent, ?BrandGuideline $brandGuidelines = null, ?array $productContext = null, string $adText = '')
    {
        $this->strategyContent = $strategyContent;
        $this->brandGuidelines = $brandGuidelines;
        $this->productContext = $productContext;
        $this->adText = $adText;
    }

    /**
     * The built-in prompt template. Placeholders are substituted per
     * generation: {{brand_context}} (colour palette + visual style from the
     * brand guidelines), {{product_context}} (product details when the
     * campaign sells specific products), {{ad_text}} (the strategy's approved
     * ad copy — the only text allowed to appear in the image),
     * {{creative_strategy}} (the strategy's imagery brief).
     *
     * Asks for a DESIGNED ad creative — layout, typography, brand colour
     * panels — not a bare photograph. The old "avoid text in the image" rule
     * dated from a model generation whose text rendering was unreliable;
     * current image models render type accurately, and finished ads with a
     * headline outperform captionless photos.
     */
    public static function defaultTemplate(): string
    {
        return "Create a finished, scroll-stopping advertising creative — a professionally DESIGNED composition (layout, typography, colour panels), not a plain photograph. Think polished brand social/display advertising.\n\n".
               "{{brand_context}}{{product_context}}\n\n".
               "**APPROVED AD TEXT (the only words allowed in the image):**\n".
               "{{ad_text}}\n\n".
               "**CREATIVE STRATEGY:**\n".
               "{{creative_strategy}}\n\n".
               "**DESIGN REQUIREMENTS:**\n".
               "- Square 1:1 composition, 1024x1024, designed mobile-first: clear focal point, high contrast, legible at thumbnail size\n".
               "- Use the brand colour palette for backgrounds, panels and accents; generous negative space\n".
               "- One short, punchy headline set in clean modern typography, taken from the approved ad text above (shorten it if needed — never write new claims)\n".
               "- Photorealistic product or lifestyle imagery integrated into the layout\n\n".
               "**HARD RULES:**\n".
               "- Never invent text: no statistics, review counts, star ratings, awards, prices or guarantees unless they appear word-for-word in the approved ad text\n".
               "- If no approved ad text is provided, produce a text-free designed composition\n".
               "- Every rendered word must be spelled correctly — when in doubt, use less text\n".
               "- In any small interface or document mock-up, render fine print as abstract placeholder bars, never as legible words (small generated text garbles)\n".
               "- No watermarks, no fake interface elements, no third-party logos\n".
               '- Ensure cultural sensitivity and inclusivity; no stock photo clichés';
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
            '{{ad_text}}' => $this->adText !== '' ? $this->adText : '(none provided — do not render any text)',
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
