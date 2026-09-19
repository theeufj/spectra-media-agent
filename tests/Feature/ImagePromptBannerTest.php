<?php

namespace Tests\Feature;

use App\Prompts\CreativeVariant;
use App\Prompts\ImagePrompt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Two instructions that produced exactly what they asked for.
 *
 * The first creative set generated on Gemini came back as competent
 * photographs that were not advertisements: no headline anywhere, and a flat
 * navy band across the bottom sixth of the frame. Neither is a model failure.
 *
 * The band is the template saying "leave the bottom sixth quieter — a brand
 * banner is composited there afterwards" on an account where no banner is ever
 * composited, because that only happens for paying customers. A sixth of the
 * canvas, reserved for something that never arrives, filled in with brand
 * colour because that is the most reasonable reading of the instruction.
 */
class ImagePromptBannerTest extends TestCase
{
    use DatabaseTransactions;

    private const SCENE = 'A maker in a sunlit studio.';

    private function prompt(bool $banner, ?string $headline = null): string
    {
        return (new ImagePrompt(self::SCENE, null, null, "Build a Store in 5 Minutes\nAll-In-One for \$35/Mo", $banner, $headline))->getPrompt();
    }

    public function test_space_is_reserved_only_when_a_banner_is_coming(): void
    {
        $this->assertStringContainsString('bottom sixth quieter', $this->prompt(true));
    }

    public function test_nothing_is_reserved_when_no_banner_will_be_composited(): void
    {
        $free = $this->prompt(false);

        $this->assertStringNotContainsString('bottom sixth quieter', $free);
        $this->assertStringContainsString('runs to all four edges', $free);
        $this->assertStringContainsString('Do not leave an empty band', $free);
    }

    public function test_the_artwork_carries_no_text_at_all(): void
    {
        /*
           It did ask for the headline, on the reasoning that current image
           models set type accurately. Not this one: two revisions of "keep it
           inside the frame" both failed on the same creative — "Build a Store
           in 5 Minute", then "e a Chat, Get a S" — and the second drew a navy
           border around a different creative while trying to describe a safe
           area. The words are composited afterwards now, so there is no longer
           any text for the model to crop, garble or misread a brief into.
        */
        $prompt = $this->prompt(true, 'Build a Store in 5 Minutes');

        $this->assertStringContainsString('NO WORDS AT ALL', $prompt);
        $this->assertStringContainsString('carries no text of any kind', $prompt);
        $this->assertStringNotContainsString('Set this exact line', $prompt);
    }

    public function test_the_headline_text_is_never_shown_to_the_model(): void
    {
        // Naming it invites it onto the artwork, in the wrong typeface,
        // alongside the one we composite.
        $this->assertStringNotContainsString('Build a Store in 5 Minutes', $this->prompt(true, 'Build a Store in 5 Minutes'));
    }

    public function test_room_is_asked_for_where_the_headline_will_go(): void
    {
        $prompt = $this->prompt(true, 'Build a Store in 5 Minutes');

        $this->assertStringContainsString('LEAVE ROOM FOR THE HEADLINE', $prompt);
        $this->assertStringContainsString('keep the upper third calm and uncluttered', $prompt);

        // A reserved panel is the failure this whole thread started with.
        $this->assertStringContainsString('do not leave a coloured box, bar or panel', $prompt);
    }

    public function test_no_room_is_asked_for_when_there_is_no_headline(): void
    {
        $this->assertStringNotContainsString('LEAVE ROOM FOR THE HEADLINE', $this->prompt(true));
    }

    public function test_borders_and_mattes_are_refused(): void
    {
        // My own margin wording produced a navy frame drawn around an entire
        // photograph, which is the model obeying "clear space around it".
        $this->assertStringContainsString('No borders, no frames, no mattes', $this->prompt(true, 'Build a Store in 5 Minutes'));
    }

    public function test_the_benefit_lens_keeps_the_subject_recognisable(): void
    {
        // A distant establishing shot loses the offer at ad thumbnail sizes.
        $benefit = CreativeVariant::apply(self::SCENE, 1);

        $this->assertStringContainsString('recognisable at thumbnail size', $benefit);
        $this->assertStringContainsString('Keep the subject prominent', $benefit);
        $this->assertStringContainsString('no distant establishing view', $benefit);
    }
}
