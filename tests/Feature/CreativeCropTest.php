<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\Campaign;
use App\Models\Strategy;
use Tests\TestCase;

/**
 * Filling an ad slot without throwing the picture away.
 *
 * cover() scaled and then cropped off whatever did not fit: 6.2% of the height
 * reaching 1200x628, 6.7% of the width reaching 300x250, and 47.7% when a
 * wrong-shaped base was substituted — the square into the landscape slot,
 * which the old code took because it was simply the first base in the array.
 * None of it was measured and all of it was content somebody briefed.
 *
 * The slot is filled by scaling now, so nothing is discarded and the cost of a
 * mismatch is distortion instead. That is only honest while the mismatch is
 * small, which is what MAX_STRETCH is for: a 6% stretch is invisible, and past
 * about 12% it shows on a face.
 */
class CreativeCropTest extends TestCase
{
    /** A real JPEG of the given size, so getimagesizefromstring() reads it. */
    private function base(int $w, int $h): array
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($im, null, 70);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return ['data' => base64_encode($bytes), 'mimeType' => 'image/jpeg'];
    }

    private function nearest(array $bases, string $wanted, int $tw, int $th): ?array
    {
        $job = new GenerateImage(Campaign::factory()->make(), new Strategy);
        $m = new \ReflectionMethod($job, 'nearestBase');

        return $m->invoke($job, $bases, $wanted, $tw, $th, 'landscape');
    }

    public function test_the_matching_aspect_is_used_untouched(): void
    {
        $bases = ['1:1' => $this->base(1024, 1024), '16:9' => $this->base(1376, 720)];

        $this->assertSame($bases['16:9'], $this->nearest($bases, '16:9', 1200, 628));
    }

    public function test_the_square_is_refused_for_the_landscape_slot(): void
    {
        /*
           1024x1024 (1.000) into a 1.911 slot is a 48% stretch — people half
           as wide as they should be. The old code took it without comment
           because it was simply the first base in the array.
        */
        $this->assertNull($this->nearest(['1:1' => $this->base(1024, 1024)], '16:9', 1200, 628));
    }

    public function test_a_small_stretch_is_accepted_rather_than_leaving_a_gap(): void
    {
        // 1376x768 (1.792) into 1.911 is a 6% stretch: invisible, and better
        // than an ad set missing a size.
        $this->assertNotNull($this->nearest(['16:9' => $this->base(1376, 768)], '4:3', 1200, 628));
    }

    public function test_a_stretch_past_the_limit_leaves_the_format_unfilled(): void
    {
        /*
           A 4:3 base (1.333) into the 300x250 slot (1.200) is 11% — allowed.
           The same base into 1200x628 (1.911) is 30% — refused, because the
           people in it would be visibly elongated and that is a worse ad than
           no ad.
        */
        $bases = ['4:3' => $this->base(1152, 896)];

        $this->assertNotNull($this->nearest($bases, '1:1', 300, 250));
        $this->assertNull($this->nearest($bases, '1:1', 1200, 628));
    }

    public function test_the_closest_of_several_is_chosen_not_the_first(): void
    {
        /*
           Order mattered before: reset() returned whichever aspect happened to
           generate first, so the same failure produced a different crop
           depending on timing.
        */
        $bases = [
            '1:1' => $this->base(1024, 1024),
            '16:9' => $this->base(1376, 720),
        ];

        $this->assertSame($bases['16:9'], $this->nearest($bases, '4:3', 1200, 628));
    }

    public function test_nothing_generated_means_nothing_shipped(): void
    {
        $this->assertNull($this->nearest([], '1:1', 1024, 1024));
    }

    public function test_the_requested_sizes_match_the_slots_they_fill(): void
    {
        $sizes = (new \ReflectionClass(GenerateImage::class))->getConstant('GROK_SIZES');

        $slots = ['1:1' => 1024 / 1024, '16:9' => 1200 / 628, '4:3' => 300 / 250];

        foreach ($slots as $aspect => $slotRatio) {
            [$w, $h] = array_map('intval', explode('x', $sizes[$aspect]));

            // Within a hair of the slot's own proportions, so cover() is a
            // downscale rather than a crop.
            $this->assertEqualsWithDelta($slotRatio, $w / $h, 0.01, "the {$aspect} request does not match its ad slot");
        }
    }
}
