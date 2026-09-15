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
     * ASKS FOR A PHOTOGRAPH, NOT A BUILT GRAPHIC, AND NOT ONE WORD OF TEXT.
     *
     * It did ask for the headline for a while, on the reasoning that current
     * image models set type accurately and an ad with a headline outperforms a
     * captionless photo. The first half turned out not to be true of this one.
     * Two revisions of "keep the headline inside the frame" both failed on the
     * same creative — "Build a Store in 5 Minute", then "e a Chat, Get a S" —
     * and the second revision drew a navy border around a different creative
     * while trying to describe a safe area. A model sizing type to a
     * composition has no reliable notion of one, and every attempt to describe
     * it perturbs the rest of the frame.
     *
     * So the words are composited afterwards, at a measured size, and this
     * asks only for somewhere quiet to put them. That also fixes what the
     * prompt could never do: the square, landscape and MREC crops of one
     * picture now carry the same words in the same place, rather than three
     * separate renderings of them.
     *
     * The history behind the remaining rules, all of it earned:
     *
     * "Finished, designed composition — layout, typography and colour panels"
     * was too much licence, and the model spent it on furniture: a navy slab
     * over 40% of the canvas, three line icons in circles picked out of the
     * scene rather than the product (a plant pot, a paintbrush), and dot grids
     * in the corners. On a 300x250 that left almost no photograph.
     *
     * An image model does not reliably distinguish a brief from the copy it is
     * briefing. A creative for a real estate customer came back carrying the
     * phrases "(as approved ad text)" and "Muted readable subtext" set in type
     * on the artwork — not strings from anywhere in this codebase, but the
     * model's paraphrase of instructions it had read as content. The same
     * image rendered a fake dashboard whose labels garbled into "Assisnanto
     * image", "Perteats" and "Bunnse", beside an invented property price. That
     * whole class of failure is why the no-text rule is now absolute rather
     * than a permitted vocabulary: there is no longer any text for the model
     * to misread a brief into.
     */
    public static function defaultTemplate(): string
    {
        return "You are producing the photograph for one advertisement. The photograph is the ad. Brand colour supports it; it does not compete with it.\n\n".
               "**SCENE TO DEPICT:**\n".
               "{{creative_strategy}}\n\n".
               "{{brand_context}}{{product_context}}\n".
               "**NO WORDS AT ALL:**\n".
               "This photograph carries no text of any kind. The advertisement's words are composited over it afterwards, at a measured size in the brand typeface, so anything you write here is duplicate text in the wrong face and anything you invent is a claim nobody approved. No headline, no caption, no logo, no signage, no labels, no writing on screens, packaging, walls or windows. Where lettering would naturally appear in this scene, render that surface blank.\n\n".
               '{{headline_instruction}}'.
               "**COMPOSITION:**\n".
               "- Square 1:1, 1024x1024, mobile-first: one clear focal point, high contrast, still legible as a thumbnail\n".
               "- The photograph fills the frame edge to edge. No borders, no frames, no mattes, no coloured bars — the picture is not sitting inside anything.\n".
               "- Keep the subject clear of the outer 10% on every side — this artwork is also trimmed to other ad sizes, and anything hard against an edge is lost\n".
               '{{banner_reservation}}'.
               "- Brand palette for the light and for whatever colour the scene naturally contains; generous negative space\n".
               "- Photorealistic subject matter, photographed rather than assembled\n\n".
               "**HARD RULES:**\n".
               "- No screens full of information: no dashboards, app windows, charts, tables, spreadsheets, forms or documents. A phone or laptop may appear in shot, but its screen carries only soft blocks of colour with no readable content whatsoever.\n".
               "- No invented facts: no statistics, prices, measurements, addresses, review counts, star ratings, dates or awards.\n".
               "- No placeholder furniture: no lorem ipsum, no grey lines standing in for text, no empty label chips, no UI skeletons.\n".
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
              "A headline is added to the top of this picture after you produce it. Compose for that: keep the upper third calm and uncluttered — plain wall, sky, open floor, soft background — with no faces, no product and no detail that matters up there. Do not draw the headline yourself, and do not leave a coloured box, bar or panel for it; just leave that part of the photograph quiet.\n\n"
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
