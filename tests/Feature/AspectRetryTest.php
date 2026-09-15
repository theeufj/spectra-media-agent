<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Some ad slots can only be filled by their own aspect.
 *
 * Measured across every combination the generator can produce. The 1200x628
 * landscape slot takes a 16:9 base or nothing — a square stretched into it is
 * 47.7% wrong and a 4:3 is 37.2%, both far past anything honest — and the
 * square slot is equally stranded without a 1:1. So one failed generation does
 * not cost a few per cent of quality, it costs the whole format, and the set
 * ships unable to serve that placement.
 *
 * Runs on production settled at seven and nine rows where twelve were possible.
 * This pins the arithmetic that makes a retry the only available fix: no
 * threshold tuning reaches these numbers without shipping visibly distorted
 * people.
 */
class AspectRetryTest extends TestCase
{
    private const MAX_STRETCH = 0.12;

    private const MAX_CROP = 0.34;

    /** The pixel sizes each aspect is requested at. */
    private const SOURCES = [
        '1:1' => [1024, 1024],
        '16:9' => [1376, 720],
        '4:3' => [1200, 1000],
    ];

    private const SLOTS = [
        'square' => [1024, 1024],
        'landscape' => [1200, 628],
        'mrec' => [300, 250],
    ];

    /** What the fill policy decides for one base against one slot. */
    private function outcome(string $aspect, string $slot): string
    {
        [$sw, $sh] = self::SOURCES[$aspect];
        [$tw, $th] = self::SLOTS[$slot];

        $stretch = abs(1 - (($sw / $sh) / ($tw / $th)));

        if ($stretch <= self::MAX_STRETCH) {
            return 'scale';
        }

        $scale = max($tw / $sw, $th / $sh);
        $crop = 1 - ($tw * $th) / (($sw * $scale) * ($sh * $scale));

        return $crop <= self::MAX_CROP ? 'crop' : 'refused';
    }

    public function test_the_landscape_slot_has_no_substitute(): void
    {
        $this->assertSame('scale', $this->outcome('16:9', 'landscape'));

        // Nothing else is close enough, which is why losing the 16:9
        // generation loses the format outright.
        $this->assertSame('refused', $this->outcome('1:1', 'landscape'));
        $this->assertSame('refused', $this->outcome('4:3', 'landscape'));
    }

    public function test_the_square_slot_has_one_substitute(): void
    {
        $this->assertSame('scale', $this->outcome('1:1', 'square'));
        $this->assertSame('crop', $this->outcome('4:3', 'square'));
        $this->assertSame('refused', $this->outcome('16:9', 'square'));
    }

    public function test_the_mrec_slot_is_the_forgiving_one(): void
    {
        // A square covers it at a 16.7% crop, so MREC is rarely the gap.
        $this->assertSame('scale', $this->outcome('4:3', 'mrec'));
        $this->assertSame('crop', $this->outcome('1:1', 'mrec'));
    }

    public function test_every_requested_size_matches_the_slot_it_serves(): void
    {
        /*
           The reason two of the three are exact rather than approximate: the
           sizes asked for are the ad slots' own proportions, so the common case
           is a pure downscale and the substitution rules only ever apply to a
           generation that failed.
        */
        foreach (['1:1' => 'square', '16:9' => 'landscape', '4:3' => 'mrec'] as $aspect => $slot) {
            $this->assertSame('scale', $this->outcome($aspect, $slot));
        }
    }
}
