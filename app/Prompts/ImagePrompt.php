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

    /**
     * Retained for the admin override template, which may still reference
     * {{ad_text}}. The built-in template no longer does: the artwork carries
     * no words at all.
     */
    private string $adText;

    /**
     * Whether a brand banner will be composited over the finished artwork.
     *
     * It only is for paying accounts. The template reserved the bottom sixth
     * for it unconditionally, so on a free account a sixth of every canvas was
     * held back for something that never arrived — and the model filled it the
     * way it was asked to, with a flat block of brand colour. That navy slab
     * across the bottom of an otherwise good photograph is not a model
     * failure; it is us asking for it.
     */
    private bool $bannerComposited;

    /**
     * The approved headline this particular creative should lead with.
     *
     * Null leaves the choice to the model, which is right when there is no
     * approved copy to nominate from. When there is, the caller rotates
     * through the set: given all five and asked for "one of those lines", the
     * model chose the same one four times, and four photographs of a single
     * message cannot tell an account which message works.
     */
    private ?string $headline;

    public function __construct(string $strategyContent, ?BrandGuideline $brandGuidelines = null, ?array $productContext = null, string $adText = '', bool $bannerComposited = true, ?string $headline = null)
    {
        $this->strategyContent = $strategyContent;
        $this->brandGuidelines = $brandGuidelines;
        $this->productContext = $productContext;
        $this->adText = $adText;
        $this->bannerComposited = $bannerComposited;
        $this->headline = $headline;
    }

    /**
     * The built-in prompt template. Placeholders are substituted per
     * generation: {{brand_context}} (colour palette + visual style from the
     * brand guidelines), {{product_context}} (product details when the
     * campaign sells specific products), {{creative_strategy}} (the strategy's
     * imagery brief), {{headline_instruction}} (where to leave room for the
     * headline we composite), {{banner_reservation}} (whether to leave the
     * bottom sixth for a brand banner).
     *
     * Generate the artwork only. Typography and banners are composited at a
     * measured size afterwards. Preserve the brief's visual medium instead of
     * forcing product renders and illustrations into lifestyle photography.
     */
    public static function defaultTemplate(): string
    {
        return "You are an advertising art director producing one distinctive image asset. Make the supplied selling idea immediately visible through one dominant subject, deliberate colour and a purposeful composition.\n\n".
               "**SCENE TO DEPICT:**\n".
               "{{creative_strategy}}\n\n".
               "{{brand_context}}{{product_context}}\n".
               "**NO WORDS AT ALL:**\n".
               "This artwork carries no text of any kind. Where the placement permits a designed ad, approved words are typeset separately. Search and responsive image assets remain free of added text. Anything you invent is a claim nobody approved. No headline, no caption, no logo, no signage, no labels, no writing on screens, packaging, walls or windows. Choose a scene and framing that do not depend on writing; do not substitute blank panels or pretend layouts for it.\n\n".
               '{{headline_instruction}}'.
               "**COMPOSITION:**\n".
               "- Use the aspect ratio requested by the generation API; mobile-first: one clear focal point, high contrast, still legible as a thumbnail\n".
               "- The artwork fills the frame edge to edge. No borders, no frames, no mattes, no coloured bars — the picture is not sitting inside anything.\n".
               "- Keep the subject clear of the outer 10% on every side — each size must preserve the focal subject, and anything hard against an edge is lost\n".
               '{{banner_reservation}}'.
               "- Use the brand palette deliberately in the subject, materials, background and light; reserve only the breathing room the composition needs\n".
               "- Honour the specified visual medium: product photography, editorial photography, tactile still life, illustration or purposeful 3D. Do not convert every concept into a lifestyle photograph\n\n".
               "**ART DIRECTION:**\n".
               "- Preserve the actual product, feature or service action that makes this concept specific to the offer. A generic attractive scene is insufficient.\n".
               "- Use precise lighting, material texture, scale and colour contrast to lead the eye. Keep one selling idea readable at thumbnail size.\n".
               "- People, desks and devices are optional. Do not add an office worker, a tired owner or piles of paperwork to make an abstract offer look real.\n".
               "- A simple conceptual object is allowed only when the brief calls for it; avoid unrelated metaphors or decorative filler. Keep any Search-specific requirement for a directly relevant product/service image.\n\n".
               "**HARD RULES:**\n".
               "- No fabricated interfaces or documents: no dashboards, app windows, charts, tables, spreadsheets, forms or mockups. A device may appear incidentally with its screen facing away; never make a blank screen or coloured placeholder blocks the focal subject.\n".
               "- No invented facts: no statistics, prices, measurements, addresses, review counts, star ratings, dates or awards.\n".
               "- No placeholder furniture: no lorem ipsum, no grey lines standing in for text, no empty label chips, no UI skeletons.\n".
               "- No decorative furniture: no icon sets, no line-art symbols in circles, no dot grids, no abstract blobs or swooshes. These read as clip art, they are chosen from the scene rather than from the product, and they distract from the focal subject.\n".
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
        /*
         * Only reserve space for the banner when one is actually coming.
         *
         * Asked to leave the bottom sixth quiet with nothing to put there, the
         * model returns a flat band of brand colour — a sixth of the canvas
         * spent on a placeholder for an element that is never composited.
         */
        $bannerReservation = $this->bannerComposited
            ? "- Leave the bottom sixth quieter than the rest: a brand banner is composited there afterwards, and a busy strip underneath it makes both unreadable.\n"
            : "- The artwork runs to all four edges. Do not leave an empty band, bar or block of flat colour anywhere in the frame.\n";

        /*
         * Nominated rather than chosen, when the caller has nominated one.
         * The markers still carry the whole approved set — that is the
         * vocabulary the artwork may use — and this says which line leads.
         */
        /*
         * The headline is set by us, not by you.
         *
         * Two revisions of "keep the headline inside the frame" failed on the
         * same creative — "Build a Store in 5 Minute", then "e a Chat, Get a
         * S" — and the second drew a navy border around a different one while
         * trying. A model sizing type has no notion of a safe area, so the
         * words are composited afterwards at a measured size and this asks
         * only for somewhere to put them.
         */
        $headlineInstruction = $this->headline !== null && trim($this->headline) !== ''
            ? "**LEAVE ROOM FOR THE HEADLINE:**\n".
              "A headline is added to the top of this picture after you produce it. Compose for that: keep the upper third calm and uncluttered — plain wall, sky, open floor, soft background — with no faces, no product and no detail that matters up there. Do not draw the headline yourself, and do not leave a coloured box, bar or panel for it; just leave that part of the artwork quiet.\n\n"
            : '';

        return strtr(self::activeTemplate(), [
            '{{ad_text}}' => $this->adText,
            '{{headline_instruction}}' => $headlineInstruction,
            '{{banner_reservation}}' => $bannerReservation,
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

        /*
         * getFormattedColorPalette() is the designer-facing form: hex codes and
         * usage notes about banners, UI elements and callouts. None of that
         * survives contact with an image model — it cannot sample a hex value,
         * and the usage prose is exactly the vocabulary that comes back drawn
         * as a fake dashboard. getImageColorDirection() names the colours and
         * stops there.
         */
        /*
           imagery_style describes the customer's WEBSITE, and saying so matters.

           It is extracted from their site, so for a SaaS brand it comes back as
           things like "icon-driven and typography-focused" — a true statement
           about a landing page, and a direct instruction to an ad model to draw
           icons and set type. That is exactly what came back: line-art symbols
           in circles, dot grids, a navy slab. The hard rules below now forbid
           all of it, which left the prompt arguing with itself in two places.

           So it is labelled for what it is and scoped to what it is good for —
           mood, palette, how their world looks — with the precedence stated
           rather than left for the model to guess.
        */
        return "**BRAND STYLE:**\n".
               $this->brandGuidelines->getImageColorDirection().
               "**Look and feel:** {$visualStyle['overall_aesthetic']}\n".
               "**How their own website looks:** {$visualStyle['imagery_style']}\n".
               "Take mood, palette and subject matter from that. Do not take layout from it: it describes a web page, not an advertisement, and where it suggests icons, diagrams or type as decoration the HARD RULES below override it.\n\n";
    }
}
