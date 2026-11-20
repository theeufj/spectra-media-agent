<?php

namespace Tests\Unit;

use App\Services\Creative\GoogleDisplayBannerRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleDisplayBannerRendererTest extends TestCase
{
    public function test_every_supported_size_contains_both_source_art_and_typeset_copy_within_the_upload_limit(): void
    {
        $renderer = new GoogleDisplayBannerRenderer;
        $source = $this->source();
        $expected = explode(' ', '200x200 240x400 250x250 250x360 300x250 336x280 580x400 120x600 160x600 300x600 300x1050 468x60 728x90 930x180 970x90 970x250 980x120 300x50 320x50 320x100');
        $this->assertEqualsCanonicalizing($expected, array_keys($renderer::SIZES));

        foreach ($renderer::SIZES as $size => [$width, $height]) {
            $bytes = $renderer->render($source, $size, 'Éclat Studio', 'Design your next chapter', 'See our Work');
            $this->assertSame([$width, $height, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3), $size);
            $this->assertLessThanOrEqual(150_000, strlen($bytes), $size);
            $image = imagecreatefromstring($bytes);
            $art = $paper = $ink = 0;
            for ($x = 0; $x < $width; $x += 3) {
                for ($y = 0; $y < $height; $y += 3) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = $rgb >> 16;
                    $g = ($rgb >> 8) & 255;
                    $b = $rgb & 255;
                    $art += (int) ($r > 130 && $g < 110 && $b < 110);
                    $paper += (int) ($r > 215 && $g > 215 && $b > 200);
                    $ink += (int) ($r < 65 && $g < 75 && $b < 80);
                }
            }
            $this->assertGreaterThan(20, $art, $size);
            $this->assertGreaterThan(20, $paper, $size);
            $this->assertGreaterThan(20, $ink, $size);
        }
    }

    public function test_long_legal_brand_and_full_native_cta_render_in_every_supported_size(): void
    {
        $renderer = new GoogleDisplayBannerRenderer;
        $source = $this->source();
        $headline = 'See all your campaigns at once';
        $this->assertSame(30, mb_strlen($headline));
        foreach ($renderer::SIZES as $size => [$width, $height]) {
            $brand = 'McKenzie, Bernier and Greenough Consulting';
            $bytes = $renderer->render($source, $size, $brand, $headline, 'See Your Campaigns');
            $this->assertSame([$width, $height, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3), $size);
            $this->assertLessThanOrEqual($renderer::MAX_BYTES, strlen($bytes), $size);
            // A change to the final word must remain visible in every CTA.
            $this->assertNotSame($bytes, $renderer->render($source, $size, $brand, $headline, 'See Your Customers'), $size);
        }
    }

    public function test_smallest_banner_preserves_case_and_is_deterministic(): void
    {
        $renderer = new GoogleDisplayBannerRenderer;
        $source = $this->source();
        $render = fn (string $brand, string $headline, string $cta) => $renderer->render($source, '300x50', $brand, $headline, $cta);
        $bytes = $render('Acme', 'Make it yours', 'Shop Now');
        $this->assertSame($bytes, $render('Acme', 'Make it yours', 'Shop Now'));
        $this->assertNotSame($bytes, $render('ACME', 'Make it yours', 'Shop Now'));
        $this->assertNotSame($bytes, $render('Acme', 'Make It Yours', 'Shop Now'));
        $this->assertNotSame($bytes, $render('Acme', 'Make it yours', 'SHOP NOW'));
    }

    public function test_brand_colour_is_faithful_and_light_and_dark_colours_receive_contrasting_ink(): void
    {
        $renderer = new GoogleDisplayBannerRenderer;
        $source = $this->source();
        foreach (['#006' => [0, 0, 102, true], '#ffef99' => [255, 239, 153, false]] as $colour => [$r, $g, $b, $lightInk]) {
            $bytes = $renderer->render($source, '300x250', 'Éclat', 'Ideas in colour', 'See our Work', $colour);
            $image = imagecreatefromstring($bytes);
            $background = imagecolorat($image, 297, 245);
            $this->assertEqualsWithDelta($r, $background >> 16, 4);
            $this->assertEqualsWithDelta($g, ($background >> 8) & 255, 4);
            $this->assertEqualsWithDelta($b, $background & 255, 4);
            $contrasting = 0;
            for ($x = 10; $x < 290; $x += 2) {
                for ($y = 115; $y < 245; $y += 2) {
                    $rgb = imagecolorat($image, $x, $y);
                    $channels = [$rgb >> 16, ($rgb >> 8) & 255, $rgb & 255];
                    $contrasting += (int) ($lightInk ? min($channels) >= 220 : max($channels) <= 35);
                }
            }
            $this->assertGreaterThan(60, $contrasting);
        }
        $plain = $renderer->render($source, '300x50', 'Acme', 'Ideas', 'Try Now');
        $this->assertSame($plain, $renderer->render($source, '300x50', 'Acme', 'Ideas', 'Try Now', 'invalid'));
    }

    #[DataProvider('unrenderable')]
    public function test_invalid_input_cannot_produce_an_uploadable_banner(string $size, string $brand, string $headline, string $cta, bool $badImage = false): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new GoogleDisplayBannerRenderer)->render($badImage ? 'bad image' : $this->source(), $size, $brand, $headline, $cta);
    }

    public static function unrenderable(): array
    {
        return [
            ['1080x1080', 'Acme', 'A sale', 'Shop'],
            ['300x50', ' ', 'A sale', 'Shop'],
            ['300x50', 'Acme', '', 'Shop'],
            ['300x50', 'Acme', 'A sale', " \t "],
            ['300x50', 'Acme', 'A sale', str_repeat('VeryLong', 14)],
            ['300x250', 'Acme', 'A sale', 'Shop', true],
            ['300x250', 'Acme', '買東西', 'Shop'],
        ];
    }

    private function source(): string
    {
        $image = imagecreatetruecolor(90, 60);
        imagefill($image, 0, 0, 0xD13621);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
