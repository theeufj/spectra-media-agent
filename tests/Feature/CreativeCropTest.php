<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\Campaign;
use App\Models\Strategy;
use Tests\TestCase;

/**
 * How much of the photograph survives reaching each ad slot.
 *
 * cover() scales and then crops away whatever does not fit, and the difference
 * was being thrown away unmeasured. Two of the three slots cost a few per cent,
 * which the brief's 10% safe margin absorbs. The expensive case is
 * substitution: when one aspect fails to generate, the code took the first base
 * it had — so the square went into the 1200x628 slot and cover() discarded
 * 47.7% of its height. Half the picture, silently, and increasingly often while
 * the image providers are rate-limiting.
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
           1024x1024 into 1200x628 is a 47.7% crop of the height. The old code
           took it without comment because it was simply the first base in the
           array.
        */
        $this->assertNull($this->nearest(['1:1' => $this->base(1024, 1024)], '16:9', 1200, 628));
    }

    public function test_a_near_enough_shape_is_still_used(): void
    {
        // 16:9 into a 1.911 slot costs ~6%, which the safe margin absorbs.
        // Refusing that would leave the ad set short a size for no gain.
        $bases = ['16:9' => $this->base(1344, 768)];

        $this->assertNotNull($this->nearest($bases, '4:3', 1200, 628));
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
