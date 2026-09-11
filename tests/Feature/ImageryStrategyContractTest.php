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

    public function test_approved_copy_is_the_only_thing_between_the_markers(): void
    {
        $prompt = (new ImagePrompt('A scene.', null, [], "Sell Your Home For More\nFree Appraisal"))->getPrompt();

        $this->assertSame(
            "Sell Your Home For More\nFree Appraisal",
            $this->markerBlock($prompt)
        );
    }

    public function test_absent_ad_copy_leaves_the_markers_genuinely_empty(): void
    {
        // This slot used to be filled with "(none provided — do not render any
        // text)" — a parenthetical direction dropped into the one place the
        // template describes as the only words allowed in the artwork. The
        // creative came back with "(as approved ad text)" set in type.
        $prompt = (new ImagePrompt('A scene.', null, [], ''))->getPrompt();

        $this->assertSame('', $this->markerBlock($prompt));
        $this->assertStringNotContainsString('none provided', $prompt);
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

    private function markerBlock(string $prompt): string
    {
        $this->assertMatchesRegularExpression('/<<<\n.*?\n>>>/s', $prompt, 'The ad-copy markers are missing.');
        preg_match('/<<<\n(.*?)\n>>>/s', $prompt, $m);

        return $m[1];
    }
}
