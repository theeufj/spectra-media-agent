<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Prompts\ImagePrompt;
use App\Prompts\ImagePromptSplitterPrompt;
use App\Prompts\StrategyPrompt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The imagery chain must hand an image model a scene, not a design brief.
 *
 * `imagery_strategy` is written by the strategy agent and fed almost verbatim
 * to an image model. It was being written as media-planning prose, because the
 * prompt's own worked example demonstrated exactly that — "For Responsive
 * Display Ads, use high-contrast infographics..." — and the model copied it.
 * Four of the six most recent live strategies open with that construction.
 *
 * What comes back from an image model given that input is what you would
 * expect: a real customer's creative arrived carrying the phrases "(as
 * approved ad text)" and "Muted readable subtext" set in type on the artwork,
 * beside a fake dashboard whose labels garbled into "Assisnanto image",
 * "Perteats" and "Bunnse", an invented property price and an invented floor
 * area. None of those strings exist in this codebase; they are the model
 * drawing its own instructions.
 *
 * So the contract is pinned at all three layers — what the strategy agent is
 * asked for, what the splitter does with it, and what reaches the image model.
 * Prose drifts back to its training distribution the moment nobody is looking,
 * and "imagery strategy" reads overwhelmingly as a designer's brief.
 */
class ImageryStrategyContractTest extends TestCase
{
    use DatabaseTransactions;

    /** Hex codes cannot be honoured by an image model, and get drawn as glyphs. */
    private const HEX = '/#[0-9A-Fa-f]{6}\b/';

    /** Ad-format vocabulary describes where an ad runs, not what it shows. */
    private const FORMAT_WORDS = [
        'Responsive Display', 'Performance Max', 'MREC', 'carousel',
        'image extension', 'responsive search ad',
    ];

    private function guideline(): BrandGuideline
    {
        $g = new BrandGuideline;
        $g->color_palette = [
            'primary_colors' => ['#1e3a5f', '#111827'],
            'secondary_colors' => ['#c4a47c'],
        ];
        $g->visual_style = [
            'overall_aesthetic' => 'Calm and premium',
            'imagery_style' => 'Natural light, unstaged',
            'description' => 'Reserve #C4A470 for refined accents and premium feature callouts',
        ];

        return $g;
    }

    /** @return list<string> every imagery_strategy value in the built prompt */
    private function imageryExamples(string $prompt): array
    {
        preg_match_all('/"imagery_strategy":\s*"((?:[^"\\\\]|\\\\.)*)"/', $prompt, $m);

        return $m[1];
    }

    private function builtStrategyPrompt(): string
    {
        return StrategyPrompt::build(Campaign::factory()->create());
    }

    public function test_the_worked_examples_describe_a_scene_rather_than_an_ad_format(): void
    {
        $examples = $this->imageryExamples($this->builtStrategyPrompt());

        $this->assertNotEmpty($examples, 'The strategy prompt must show worked imagery examples.');

        foreach ($examples as $example) {
            $this->assertDoesNotMatchRegularExpression(
                self::HEX,
                $example,
                "An imagery example demonstrates a hex code, which teaches the model to emit them: {$example}"
            );

            foreach (self::FORMAT_WORDS as $word) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $word,
                    $example,
                    "An imagery example demonstrates the ad-format word '{$word}': {$example}"
                );
            }
        }
    }

    public function test_the_strategy_agent_is_told_what_an_imagery_strategy_is_not(): void
    {
        $prompt = $this->builtStrategyPrompt();

        // The instruction itself may name the anti-patterns; the examples may not.
        $this->assertStringContainsString('NO hex codes', $prompt);
        $this->assertStringContainsStringIgnoringCase('dashboard', $prompt);
        $this->assertStringContainsStringIgnoringCase('fed almost verbatim', $prompt);
    }

    public function test_the_splitter_refuses_interfaces_and_text_cards(): void
    {
        $prompt = (new ImagePromptSplitterPrompt('anything'))->getPrompt();

        $this->assertStringContainsStringIgnoringCase('dashboard', $prompt);
        $this->assertStringContainsStringIgnoringCase('nothing legible', $prompt);

        // Its own examples must not model a CTA card, which is what it is there to drop.
        preg_match_all('/"([^"]{40,})"/', $prompt, $m);
        foreach ($m[1] as $example) {
            $this->assertStringNotContainsStringIgnoringCase('call to action', $example);
            $this->assertDoesNotMatchRegularExpression(self::HEX, $example);
        }
    }

    public function test_no_placeholder_survives_into_the_image_prompt(): void
    {
        $prompt = (new ImagePrompt('A couple outside a home at golden hour.', $this->guideline(), [], 'Sell For More'))->getPrompt();

        $this->assertDoesNotMatchRegularExpression('/\{\{[a-z_]+\}\}/', $prompt);
    }

    public function test_no_approved_copy_is_shown_to_the_image_model(): void
    {
        /*
           The markers are gone, and with them the whole class of failure they
           existed to contain.

           They fenced the approved copy so "the only words allowed" was a
           property of a delimited block rather than a sentence the model could
           itself draw. That worked as far as it went, but the model still had
           to set the type — and it cropped it: "Build a Store in 5 Minute",
           then "e a Chat, Get a S", two revisions apart. The headline is
           composited afterwards at a measured size now, so the artwork is
           asked for no words at all and the copy never reaches it.
        */
        $prompt = (new ImagePrompt('A scene.', null, [], "Sell Your Home For More\nFree Appraisal"))->getPrompt();

        $this->assertStringNotContainsString('Sell Your Home For More', $prompt);
        $this->assertStringNotContainsString('Free Appraisal', $prompt);
        $this->assertStringContainsString('NO WORDS AT ALL', $prompt);
    }

    public function test_the_artwork_is_refused_every_kind_of_lettering(): void
    {
        // Signage and screen text were the routes by which invented claims and
        // garbled words ("Assisnanto image", "Perteats") reached creatives.
        $prompt = (new ImagePrompt('A scene.', null, [], ''))->getPrompt();

        foreach (['No headline', 'no logo', 'no signage', 'no writing on screens'] as $refusal) {
            $this->assertStringContainsString($refusal, $prompt);
        }
    }

    public function test_the_brand_palette_reaches_the_image_model_as_words_not_hex(): void
    {
        $prompt = (new ImagePrompt('A scene.', $this->guideline(), [], 'Headline'))->getPrompt();

        $this->assertDoesNotMatchRegularExpression(
            self::HEX,
            $prompt,
            'A hex code reached the image prompt; an image model cannot sample one and may draw it.'
        );

        // And the designer-facing usage prose — "reserve #C4A470 for refined
        // accents and premium feature callouts" — is the vocabulary that comes
        // back rendered as a fake UI full of labels.
        $this->assertStringNotContainsStringIgnoringCase('callout', $prompt);
    }

    public function test_hex_colours_are_named_in_plain_english(): void
    {
        $this->assertStringContainsStringIgnoringCase('navy', (string) BrandGuideline::nameColour('#1e3a5f'));
        $this->assertNull(BrandGuideline::nameColour('not-a-colour'));
    }

    public function test_the_splitter_is_told_that_paraphrasing_is_a_failure(): void
    {
        $prompt = (new ImagePromptSplitterPrompt('A potter in a studio holding a tablet.'))->getPrompt();

        /*
           Nine creatives for one campaign came back as the same woman in the
           same pottery studio holding the same tablet, differing only in camera
           angle. The strategy named one scene, and the splitter — asked to
           "split" something that has no parts — paraphrased it three times. A
           customer scrolling past sees one ad, three times.
        */
        $this->assertStringContainsString('Paraphrasing is a failure', $prompt);
        $this->assertStringContainsString('exactly 3 strings', $prompt);

        // The axes it must vary along, so "different" cannot be satisfied by
        // moving the camera.
        foreach (['subject', 'setting', 'shot distance', 'moment'] as $axis) {
            $this->assertStringContainsString($axis, $prompt, "the splitter is not told to vary the {$axis}");
        }
    }

    public function test_the_strategy_agent_is_asked_for_a_range_not_one_scene(): void
    {
        $prompt = $this->builtStrategyPrompt();

        // One scene in produces one picture out, however many times it is
        // rendered. The range has to be named where the scene is named.
        $this->assertStringContainsString('Also suits:', $prompt);
        $this->assertStringContainsString('the same picture three times', $prompt);
    }

    public function test_the_image_model_is_refused_the_clip_art_it_reaches_for(): void
    {
        $prompt = (new ImagePrompt('A potter at work.'))->getPrompt();

        /*
           Given "finished, designed composition — layout, typography and colour
           panels", the model spent the licence on furniture: a navy slab over
           40% of the canvas, three line icons in circles picked from the scene
           rather than the product — a plant pot, a paintbrush — and dot grids
           in the corners. On a 300x250 almost no photograph survived.
        */
        $this->assertStringContainsString('no icon sets', $prompt);
        $this->assertStringContainsString('no dot grids', $prompt);

        /*
           The quarter-of-the-picture cap on colour panels went with the text:
           with nothing to put in a panel, the rule to state is that there are
           no panels. My own attempt at a margin instruction had the model draw
           a navy frame around an entire photograph, so this is the refusal
           that matters now.
        */
        $this->assertStringContainsString('No borders, no frames, no mattes', $prompt);

        // And the strip our own banner is composited onto has to stay quiet,
        // or the two fight each other.
        $this->assertStringContainsString('bottom sixth', $prompt);
    }

    public function test_a_website_style_does_not_brief_an_advertisement(): void
    {
        // No factory for this model; constructed directly, as the tests
        // above it do.
        $brand = new BrandGuideline([
            'visual_style' => [
                'overall_aesthetic' => 'modern minimalist SaaS',
                // Extracted from the customer's own site. True of a landing
                // page, and a direct instruction to an ad model to draw icons.
                'imagery_style' => 'icon-driven and typography-focused',
            ],
            'color_palette' => ['primary_colors' => ['#073852']],
        ]);

        $prompt = (new ImagePrompt('A maker in a studio.', $brand))->getPrompt();

        /*
           This line is why the creatives came back carrying line-art symbols in
           circles and dot grids: the brand's own imagery_style said to. Once the
           hard rules forbade that furniture, the prompt was arguing with itself
           — brand style asking for icons, hard rules refusing them, and nothing
           saying which won.
        */
        $this->assertStringContainsString('How their own website looks', $prompt);
        $this->assertStringContainsString('describes a web page, not an advertisement', $prompt);
        $this->assertStringContainsString('HARD RULES below override it', $prompt);

        // The style itself still reaches the model — it is good for mood and
        // palette, which is the half worth keeping.
        $this->assertStringContainsString('icon-driven and typography-focused', $prompt);
    }

    public function test_the_scene_must_be_one_no_other_business_could_use(): void
    {
        $prompt = $this->builtStrategyPrompt();

        /*
           Every rule in this block governed form — name a subject, plain
           visual English, no hex codes, one or two sentences, add "Also
           suits:" — and a stock caption satisfies all of them. A live
           customer's strategy came back as "A startup founder sitting at a
           clean desk in a modern, well-lit office, focused on a laptop
           screen", which is fully compliant and describes nobody. Nothing
           asked for the scene to be about this business.
        */
        $this->assertStringContainsString('UNUSABLE BY ANY OTHER BUSINESS', $prompt);
        $this->assertStringContainsString('swapping a single noun', $prompt);
    }

    public function test_a_software_business_is_shown_how_to_photograph_the_problem(): void
    {
        $prompt = $this->builtStrategyPrompt();

        /*
           All three worked examples were businesses whose customer does
           something physical — a letting agent in a front room, a tradesperson
           at a kerbside, friends over coffee. A company whose product is
           software had no demonstrated route to a specific picture, so the
           model reached for the only thing it knew: a person at a screen.
        */
        $this->assertStringContainsString('PHOTOGRAPH THE PROBLEM, NOT THE PRODUCT', $prompt);
        $this->assertStringContainsString('unphotographable', $prompt);
    }

    public function test_the_stock_caption_is_shown_only_as_the_thing_not_to_write(): void
    {
        $prompt = $this->builtStrategyPrompt();

        // Quoted verbatim from a live strategy, and it must never sit behind
        // a "Good:" — an example teaches far harder than a rule, which is how
        // the media-planning prose got in here in the first place.
        $this->assertStringContainsString(
            'Bad: "A startup founder sitting at a clean desk',
            $prompt,
        );
        $this->assertStringNotContainsString(
            'Good: "A startup founder sitting at a clean desk',
            $prompt,
        );
    }

    public function test_the_imagery_rules_point_at_the_pain_points_already_in_the_prompt(): void
    {
        $brand = new BrandGuideline([
            'brand_voice' => ['primary_tone' => 'Direct', 'description' => 'Plain and warm'],
            'tone_attributes' => ['friendly', 'practical'],
            'unique_selling_propositions' => ['One place for every startup perk'],
            'color_palette' => [
                'primary_colors' => ['#1e3a5f'],
                'secondary_colors' => ['#c4a47c'],
                'description' => 'Navy with a warm accent',
            ],
            'target_audience' => [
                'primary' => 'Early-stage startup founders',
                'demographics' => 'Technical founders at seed stage',
                'psychographics' => 'Efficiency-focused and value-conscious',
                'language_level' => 'Plain and direct',
                'pain_points' => ['Perks scattered across email, chat and spreadsheets'],
            ],
        ]);

        $prompt = StrategyPrompt::build(Campaign::factory()->create(), null, [], $brand);

        /*
           The material was never missing. The prompt that produced the stock
           caption already carried this pain point, verbatim, a few hundred
           lines above the imagery rules — and the imagery rules never once
           referred back to it. Both halves have to be present for either to
           be worth anything.
        */
        $this->assertStringContainsString('Perks scattered across email, chat and spreadsheets', $prompt);
        $this->assertStringContainsString('The pain points in', $prompt);
    }
}
