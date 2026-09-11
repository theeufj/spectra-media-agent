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
     *
     * The shape of this template matters as much as its content, because an
     * image model does not reliably distinguish a brief from the copy it is
     * briefing. A creative generated for a real estate customer came back
     * carrying the phrases "(as approved ad text)" and "Muted readable
     * subtext" set in type on the artwork — not strings from anywhere in this
     * codebase, but the model's paraphrase of instructions it had been given
     * and had read as content. The same image rendered a fake dashboard whose
     * labels garbled into "Assisnanto image", "Perteats" and "Bunnse", beside
     * an invented property price and floor area.
     *
     * Hence: the scene comes first, the renderable words are fenced between
     * markers so "the only words allowed" is a property of a delimited block
     * rather than a sentence that can itself be drawn, and the rules below say
     * what the artwork may contain instead of narrating how to build it. The
     * old wording asked for "fine print as abstract placeholder bars", and got
     * exactly that: literal grey bars, drawn as a design element.
     */
    public static function defaultTemplate(): string
    {
        return "You are producing the artwork for one advertisement. Output a finished, designed composition — layout, typography and colour panels, not a bare photograph.\n\n".
               "**SCENE TO DEPICT:**\n".
               "{{creative_strategy}}\n\n".
               "{{brand_context}}{{product_context}}\n".
               "**THE ONLY WORDS THAT MAY APPEAR IN THE ARTWORK:**\n".
               "<<<\n{{ad_text}}\n>>>\n".
               "Set one short headline from inside those markers, shortening if it helps the layout. Every other glyph in the image must come from inside the markers or not exist. Nothing written anywhere else in this brief may be drawn: these are instructions to you, not copy for the page.\n\n".
               "**COMPOSITION:**\n".
               "- Square 1:1, 1024x1024, mobile-first: one clear focal point, high contrast, still legible as a thumbnail\n".
               "- Keep the headline and any subject clear of the outer 10% on every side — this artwork is also trimmed to other ad sizes, and anything hard against an edge is lost\n".
               "- Brand palette for backgrounds, panels and accents; generous negative space\n".
               "- Photorealistic subject matter integrated into the layout, not pasted onto it\n\n".
               "**HARD RULES:**\n".
               "- No screens full of information: no dashboards, app windows, charts, tables, spreadsheets, forms or documents. A phone or laptop may appear in shot, but its screen carries only soft blocks of colour with no readable content whatsoever.\n".
               "- No invented facts: no statistics, prices, measurements, addresses, review counts, star ratings, dates or awards, unless the characters appear between the markers above.\n".
               "- No placeholder furniture: no lorem ipsum, no grey lines standing in for text, no empty label chips, no UI skeletons.\n".
               "- Every rendered word must be a real, correctly spelled word. Fewer words beats risking a garbled one, and no text at all beats nonsense text.\n".
               "- If nothing appears between the markers above, produce a composition with no words in it at all.\n".
               '- No watermarks, no third-party logos, no stock-photo clichés; keep it culturally sensitive and inclusive.';
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

        /*
         * When there is no approved copy the markers stay genuinely empty.
         *
         * This used to substitute the sentence "(none provided — do not render
         * any text)" — a parenthetical instruction, dropped into the one slot
         * the template describes as the only words allowed in the artwork. An
         * image model reads that slot as content, and the observed failure is
         * exactly what that predicts: a creative came back with "(as approved
         * ad text)" set in type beneath the headline. Never put a direction
         * where the copy goes; the rules already cover the empty case.
         */
        return strtr(self::activeTemplate(), [
            '{{brand_context}}' => $brandContext,
            '{{product_context}}' => $productContextString,
            '{{ad_text}}' => $this->adText,
            '{{creative_strategy}}' => $this->strategyContent,
        ]);
    }

    private function formatBrandContext(): string
    {
        if (! $this->brandGuidelines) {
            return '';
        }

        $visualStyle = $this->brandGuidelines->visual_style;

        /*
         * getFormattedColorPalette() is the designer-facing form: hex codes and
         * usage notes about banners, UI elements and callouts. None of that
         * survives contact with an image model — it cannot sample a hex value,
         * and the usage prose is exactly the vocabulary that comes back drawn
         * as a fake dashboard. getImageColorDirection() names the colours and
         * stops there.
         */
        return "**BRAND STYLE:**\n".
               $this->brandGuidelines->getImageColorDirection().
               "**Look and feel:** {$visualStyle['overall_aesthetic']}\n".
               "**Imagery:** {$visualStyle['imagery_style']}\n\n";
    }
}
