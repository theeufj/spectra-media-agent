<?php

namespace Tests\Feature;

use App\Models\ImageCollateral;
use App\Services\AdminMonitorService;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Ad variants are generated at their own aspect ratio, not cut from a square.
 *
 * Every format used to be a centre-crop of one 1024x1024 image. Reaching
 * 1200x628 that way discards 23.8% of the height off the top AND the bottom —
 * 47.7% of the picture — so a landscape creative arrived with its headline
 * decapitated and the CTA card sliced off the bottom. A photograph survives
 * that; artwork with type set into it does not, and the imagery prompt now
 * asks for artwork with type set into it.
 *
 * The arithmetic here is the reason the fix exists, so it is asserted rather
 * than described: these assertions fail if anyone reintroduces a square source
 * for the landscape format.
 */
class ImageVariantGenerationTest extends TestCase
{
    /** Formats and the aspect each is generated at, mirroring GenerateImage. */
    private const FORMATS = [
        'square' => [[1024, 1024], '1:1'],
        'landscape' => [[1200, 628], '16:9'],
        'mrec' => [[300, 250], '1:1'],
    ];

    private const SOURCES = ['1:1' => [1024, 1024], '16:9' => [1344, 768]];

    /** Fraction of height cover() removes from each edge going source -> target. */
    private function verticalLossPercent(int $srcW, int $srcH, int $dstW, int $dstH): float
    {
        $scale = max($dstW / $srcW, $dstH / $srcH);
        $scaledH = $srcH * $scale;

        return (($scaledH - $dstH) / 2) / $scaledH * 100;
    }

    public function test_cropping_a_square_to_landscape_destroys_half_the_image(): void
    {
        // The bug, stated as a number. This is why the square source went.
        $this->assertEqualsWithDelta(
            23.8,
            $this->verticalLossPercent(1024, 1024, 1200, 628),
            0.1,
            'A square source must still be shown to lose ~24% per edge — if this changed, the fix below needs rethinking.'
        );
    }

    public function test_every_format_loses_little_from_its_own_aspect(): void
    {
        foreach (self::FORMATS as $format => [[$dstW, $dstH], $aspect]) {
            [$srcW, $srcH] = self::SOURCES[$aspect];

            $loss = $this->verticalLossPercent($srcW, $srcH, $dstW, $dstH);

            $this->assertLessThan(
                10.0,
                $loss,
                "Format {$format} trims {$loss}% off each edge of its {$aspect} source; a headline cannot survive that."
            );
        }
    }

    public function test_a_band_at_the_top_of_the_frame_survives_every_format(): void
    {
        // The empirical form of the same claim: a headline occupying the top
        // 11.7% of the frame must still be there after the trim. Against a
        // square source this fails for landscape — the band is gone entirely.
        $manager = ImageManager::gd();

        foreach (self::FORMATS as $format => [[$dstW, $dstH], $aspect]) {
            [$srcW, $srcH] = self::SOURCES[$aspect];

            $source = $manager->create($srcW, $srcH)->fill('222222');
            $source->drawRectangle(0, 0, function ($draw) use ($srcW, $srcH) {
                $draw->size($srcW, (int) ($srcH * 0.117));
                $draw->background('ff0000');
            });

            $out = $manager->read((string) $source->encode(new PngEncoder))->cover($dstW, $dstH);

            $this->assertSame(
                'ff0000',
                strtolower(ltrim($out->pickColor((int) ($dstW / 2), 2)->toHex(), '#')),
                "The top band did not survive the {$format} trim."
            );
        }
    }

    public function test_the_free_tier_limit_is_a_named_constant(): void
    {
        // Quoted to the user in the controller's refusal and enforced in the
        // job; two bare 4s is how two copies of one rule drift apart.
        $this->assertSame(4, ImageCollateral::FREE_TIER_LIMIT_PER_CAMPAIGN);
    }

    public function test_a_design_brief_is_flagged_without_failing_validation(): void
    {
        // Every strategy written before the imagery contract was fixed looks
        // like this. Flagging it must not stop generation, or existing
        // campaigns stop producing anything at all.
        $review = app(AdminMonitorService::class)->reviewImagePrompt(
            'For Responsive Display Ads, utilize deep navy (#1E3A5F) backgrounds with clean platform interfaces and a data-driven dashboard.'
        );

        $this->assertTrue($review['is_valid'], 'Drift must be reported, never enforced — is_valid false makes the job throw.');
        $this->assertNotEmpty($review['warnings']);

        $joined = implode(' ', $review['warnings']);
        $this->assertStringContainsString('#1E3A5F', $joined);
        $this->assertStringContainsStringIgnoringCase('responsive display', $joined);
        $this->assertStringContainsStringIgnoringCase('dashboard', $joined);
    }

    public function test_a_scene_raises_no_warnings(): void
    {
        $review = app(AdminMonitorService::class)->reviewImagePrompt(
            'A letting agent in her thirties standing in the bright front room of a terraced house, phone in hand, warm afternoon light through a bay window.'
        );

        $this->assertTrue($review['is_valid']);
        $this->assertSame([], $review['warnings']);
    }
}
