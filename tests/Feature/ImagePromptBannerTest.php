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

    private function prompt(bool $banner): string
    {
        return (new ImagePrompt(self::SCENE, null, null, "Build a Store in 5 Minutes\nAll-In-One for \$35/Mo", $banner))->getPrompt();
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

    public function test_the_headline_is_demanded_rather_than_suggested(): void
    {
        /*
           "Set one short headline from inside those markers" was permission,
           and Gemini read it as permission. The approved copy reached the
           prompt and none of it was drawn, which is the difference between an
           advertisement and a stock photograph.
        */
        $prompt = $this->prompt(true);

        $this->assertStringContainsString('This is required', $prompt);
        $this->assertStringContainsString('stock photograph is not what is being made', $prompt);
    }

    public function test_the_wide_lens_stays_in_the_room(): void
    {
        /*
           "Pull much further back... the person small in the frame or out of
           it entirely" produced a shopfront photographed from the far side of
           the street, the subject a smudge behind glass. True to the words,
           useless as an ad.
        */
        $wide = CreativeVariant::apply(self::SCENE, 1);

        $this->assertStringContainsString('rather than across the street', $wide);
        $this->assertStringContainsString('no exteriors', $wide);
        $this->assertStringNotContainsString('out of it entirely', $wide);
    }
}
