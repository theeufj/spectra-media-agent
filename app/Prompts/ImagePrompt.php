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
     * campaign sells specific products), {{ad_text}} (the strategy's approved
     * ad copy — the only text allowed to appear in the image),
     * {{creative_strategy}} (the strategy's imagery brief).
     *
     * Asks for a photograph carrying a headline, not a built graphic. The
     * "avoid text in the image" rule dated from a model generation whose text
     * rendering was unreliable; current image models set type accurately, and
     * an ad with a headline outperforms a captionless photo.
     *
     * But "finished, designed composition — layout, typography and colour
     * panels" was too much licence, and the model spent it on furniture: a
     * navy slab over 40% of the canvas, three line icons in circles picked out
     * of the scene rather than the product (a plant pot, a paintbrush), and
     * dot grids in the corners. On a 300x250 that left almost no photograph.
     * The panel is now capped at a quarter of the frame and the clip art is
     * named and refused.
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
        return "You are producing the artwork for one advertisement. The photograph is the ad. Brand colour supports it; it does not compete with it.\n\n".
               "**SCENE TO DEPICT:**\n".
               "{{creative_strategy}}\n\n".
               "{{brand_context}}{{product_context}}\n".
               "**THE ONLY WORDS THAT MAY APPEAR IN THE ARTWORK:**\n".
               "<<<\n{{ad_text}}\n>>>\n".
               '{{headline_instruction}}'.
               "The whole headline must fit inside the frame with clear space around it — every letter of the first word and the last word fully visible, none of it touching or running past any edge. If the line does not fit at the size you have chosen, set it smaller, break it across two lines, or choose a shorter line from between the markers. A headline with its first and last letters cropped off is worse than no headline at all.\n".
               "Every other glyph in the image must come from inside the markers or not exist. Nothing written anywhere else in this brief may be drawn: these are instructions to you, not copy for the page.\n\n".
               "**COMPOSITION:**\n".
               "- Square 1:1, 1024x1024, mobile-first: one clear focal point, high contrast, still legible as a thumbnail\n".
               "- The scene fills the frame. Any solid colour panel is a restrained accent — a corner, an edge, a band behind the headline — and never more than a quarter of the picture.\n".
               "- Keep the headline and any subject clear of the outer 10% on every side — this artwork is also trimmed to other ad sizes, and anything hard against an edge is lost\n".
               '{{banner_reservation}}'.
               "- Brand palette for the accent and for the light; generous negative space\n".
               "- Photorealistic subject matter, photographed rather than assembled\n\n".
               "**HARD RULES:**\n".
               "- No screens full of information: no dashboards, app windows, charts, tables, spreadsheets, forms or documents. A phone or laptop may appear in shot, but its screen carries only soft blocks of colour with no readable content whatsoever.\n".
               "- No invented facts: no statistics, prices, measurements, addresses, review counts, star ratings, dates or awards, unless the characters appear between the markers above.\n".
               "- No placeholder furniture: no lorem ipsum, no grey lines standing in for text, no empty label chips, no UI skeletons.\n".
               "- Every rendered word must be a real, correctly spelled word. Fewer words beats risking a garbled one, and no text at all beats nonsense text.\n".
               "- If nothing appears between the markers above, produce a composition with no words in it at all.\n".
               "- No decorative furniture: no icon sets, no line-art symbols in circles, no dot grids, no abstract blobs or swooshes. These read as clip art, they are chosen from the scene rather than from the product, and they cost the photograph the room it needs.\n".
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
            : "- The photograph runs to all four edges. Do not leave an empty band, bar or block of flat colour anywhere in the frame.\n";

        /*
         * Nominated rather than chosen, when the caller has nominated one.
         * The markers still carry the whole approved set — that is the
         * vocabulary the artwork may use — and this says which line leads.
         */
        $headlineInstruction = $this->headline !== null && trim($this->headline) !== ''
            ? 'Set this exact line as the headline in the picture: "'.trim($this->headline)."\". Use it word for word, in clean legible type, positioned and sized like the headline of a printed advertisement. This is required: an advertisement without a headline is a stock photograph, and a stock photograph is not what is being made here.\n"
            : "Set exactly one of those lines as a headline in the picture, in clean legible type, positioned and sized like the headline of a printed advertisement. This is required: an advertisement without a headline is a stock photograph, and a stock photograph is not what is being made here.\n";

        return strtr(self::activeTemplate(), [
            '{{headline_instruction}}' => $headlineInstruction,
            '{{banner_reservation}}' => $bannerReservation,
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
