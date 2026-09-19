<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The headline is measured, not negotiated.
 *
 * Two prompt revisions asked an image model to keep the headline inside the
 * frame. Both failed on the same creative — "Build a Store in 5 Minute", then
 * "e a Chat, Get a S" — and the second drew a navy border around a different
 * creative while trying to describe a safe area.
 *
 * imagettfbbox() measures real glyphs at a real size, so fitting is
 * arithmetic. The point is not typographic perfection; it is that a headline
 * which ships cannot be missing its first and last letters, because that ad
 * runs and spends money saying something we did not write.
 */
class HeadlineFittingTest extends TestCase
{
    private function font(): string
    {
        $job = new \App\Services\Creative\ImageComposer;
        $m = new \ReflectionMethod($job, 'resolveFont');
        $path = $m->invoke($job);

        if (! $path || ! function_exists('imagettfbbox')) {
            $this->markTestSkipped('No TrueType font available on this machine.');
        }

        return $path;
    }

    /** @return array{lines: list<string>, size: int}|null */
    private function fit(string $text, int $maxWidth, int $startSize, int $maxLines = 2): ?array
    {
        $job = new \App\Services\Creative\ImageComposer;
        $m = new \ReflectionMethod($job, 'fitHeadline');

        return $m->invoke($job, $text, $this->font(), $maxWidth, $startSize, $maxLines);
    }

    private function widest(array $fitted, string $font): int
    {
        $max = 0;
        foreach ($fitted['lines'] as $line) {
            $box = imagettfbbox($fitted['size'], 0, $font, $line);
            $max = max($max, (int) abs($box[2] - $box[0]));
        }

        return $max;
    }

    public function test_every_line_actually_fits_the_width(): void
    {
        $font = $this->font();
        $maxWidth = 860;

        // The headline that shipped cropped, at the width it shipped at.
        $fitted = $this->fit('Have a Chat, Get a Store', $maxWidth, 87);

        $this->assertNotNull($fitted);
        $this->assertLessThanOrEqual($maxWidth, $this->widest($fitted, $font));
    }

    public function test_no_word_is_ever_dropped(): void
    {
        $fitted = $this->fit('No Hidden Fees or Extra Apps', 860, 87);

        // Wrapping must not lose copy: the whole approved line or nothing.
        $this->assertSame('No Hidden Fees or Extra Apps', implode(' ', $fitted['lines']));
    }

    public function test_a_long_headline_shrinks_rather_than_overflowing(): void
    {
        $font = $this->font();
        $maxWidth = 500;

        $roomy = $this->fit('All-In-One Store for $35/Mo', 900, 87);
        $tight = $this->fit('All-In-One Store for $35/Mo', $maxWidth, 87);

        $this->assertLessThan($roomy['size'], $tight['size'], 'a narrower frame must produce smaller type');
        $this->assertLessThanOrEqual($maxWidth, $this->widest($tight, $font));
    }

    public function test_it_wraps_to_two_lines_before_shrinking_to_nothing(): void
    {
        $fitted = $this->fit('Have a Chat, Get a Store', 420, 87);

        $this->assertNotNull($fitted);
        $this->assertLessThanOrEqual(2, count($fitted['lines']));
    }

    public function test_an_impossible_headline_is_refused_rather_than_cropped(): void
    {
        /*
           The whole point. Given a width nothing legible fits into, the answer
           is no headline — not a headline with its ends cut off, which is what
           the model produced twice.
        */
        $this->assertNull($this->fit('Supercalifragilisticexpialidocious', 60, 87));
    }

    public function test_a_single_unbreakable_word_still_shrinks_to_fit(): void
    {
        $font = $this->font();

        $fitted = $this->fit('Extraordinary', 400, 87);

        $this->assertNotNull($fitted);
        $this->assertCount(1, $fitted['lines']);
        $this->assertLessThanOrEqual(400, $this->widest($fitted, $font));
    }
}
