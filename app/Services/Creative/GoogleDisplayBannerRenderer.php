<?php

namespace App\Services\Creative;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

/** Renders baked-in, approved copy for uploaded Google Display image ads. */
final class GoogleDisplayBannerRenderer
{
    // Static image table: https://support.google.com/google-ads/answer/1722096
    public const SIZES = [
        '200x200' => [200, 200], '240x400' => [240, 400], '250x250' => [250, 250],
        '250x360' => [250, 360], '300x250' => [300, 250], '336x280' => [336, 280],
        '580x400' => [580, 400], '120x600' => [120, 600], '160x600' => [160, 600],
        '300x600' => [300, 600], '300x1050' => [300, 1050], '468x60' => [468, 60],
        '728x90' => [728, 90], '930x180' => [930, 180], '970x90' => [970, 90],
        '970x250' => [970, 250], '980x120' => [980, 120], '300x50' => [300, 50],
        '320x50' => [320, 50], '320x100' => [320, 100],
    ];

    public const REGIONAL_SIZES = [
        '250x360' => 'SE', '580x400' => 'NO', '930x180' => 'DK', '980x120' => 'SE',
    ];

    public const MAX_BYTES = 150_000;

    private const INK = 0x142024;

    private const PAPER = 0xFFFAF0;

    public function render(string $sourceBytes, string $size, string $brand, string $headline, string $cta, ?string $brandColour = null): string
    {
        if (! isset(self::SIZES[$size])) {
            throw new InvalidArgumentException("Unsupported Google Display banner size: {$size}.");
        }
        [$width, $height] = self::SIZES[$size];
        $font = public_path('fonts/DejaVuSans-Bold.ttf');
        if (! function_exists('imagettftext') || ! is_readable($font)) {
            throw new RuntimeException('Display banners require FreeType and the bundled DejaVu Sans Bold font.');
        }
        [$brand, $headline, $cta] = array_map($this->copy(...), [$brand, $headline, $cta]);
        $this->verifyGlyphs($font, $brand.' '.$headline.' '.$cta);
        $info = strlen($sourceBytes) <= 10 * 1024 * 1024 ? @getimagesizefromstring($sourceBytes) : false;
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 8_000_000
            || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Supply a JPEG, PNG or WebP up to 10 MB and 8 megapixels.');
        }
        $source = @imagecreatefromstring($sourceBytes);
        if (! $source) {
            throw new InvalidArgumentException('The source image could not be decoded.');
        }
        $palette = $this->palette($brandColour);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, $palette['background']);

        if ($width >= 3 * $height && $height <= 120) {
            $art = (int) round($width * ($height <= 60 ? .12 : .2));
            $this->photo($canvas, $source, 0, 0, $art, $height);
            $this->strip($canvas, $font, $art, $brand, $headline, $cta, $palette);
        } else {
            if ($width >= 2 * $height) {
                $panelWidth = (int) round($width * .58);
                $this->photo($canvas, $source, $panelWidth, 0, $width - $panelWidth, $height);
                $this->panel($canvas, $font, $panelWidth, 0, $brand, $headline, $cta, $palette);
            } else {
                $art = (int) round($height * ($height > $width ? .54 : .42));
                $this->photo($canvas, $source, 0, 0, $width, $art);
                $this->panel($canvas, $font, $width, $art, $brand, $headline, $cta, $palette);
            }
        }

        foreach ([92, 85, 75, 65, 55, 45] as $quality) {
            ob_start();
            try {
                $ok = imagejpeg($canvas, null, $quality);
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if ($ok && is_string($bytes) && $bytes !== '' && strlen($bytes) <= self::MAX_BYTES) {
                return $bytes;
            }
        }
        throw new RuntimeException("The {$size} JPEG could not be encoded within Google's upload limit.");
    }

    /** @return array{background:int,foreground:int,brand:int} */
    private function palette(?string $colour): array
    {
        if (! preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/iD', trim((string) $colour), $match)) {
            return ['background' => self::INK, 'foreground' => self::PAPER, 'brand' => 0xC4D4CE];
        }
        $hex = $match[1];
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        [$red, $green, $blue] = array_map(static function (string $hex): float {
            $v = hexdec($hex) / 255;

            return $v <= .04045 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        // Pick whichever of white/black has the higher WCAG contrast ratio.
        // The chosen pair always exceeds 4.5:1, including on mid-tone colors.
        $luminance = .2126 * $red + .7152 * $green + .0722 * $blue;
        $foreground = ($luminance + .05) / .05 >= 1.05 / ($luminance + .05) ? 0x000000 : 0xFFFFFF;

        return ['background' => (int) hexdec($hex), 'foreground' => $foreground, 'brand' => $foreground];
    }

    private function copy(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || mb_strlen($text) > 200 || preg_match('/[\p{C}]/u', $text)) {
            throw new InvalidArgumentException('Supply readable brand, headline and call-to-action text.');
        }

        return $text;
    }

    private function verifyGlyphs(string $font, string $copy): void
    {
        if (! preg_match_all('/[^\x00-\x7f]/u', $copy, $matches)) {
            return;
        }
        // GD/FreeType silently paints the font's missing-glyph box. Compare its
        // actual ink, since a supported glyph can share the box's metrics.
        $missing = $this->glyph($font, "\u{0378}");
        foreach (array_unique($matches[0]) as $character) {
            if (mb_ord($character) > 0xFFFF || $this->glyph($font, $character) === $missing) {
                throw new InvalidArgumentException('The display font cannot render a character in the approved copy.');
            }
        }
    }

    private function glyph(string $font, string $character): string
    {
        $glyph = imagecreatetruecolor(40, 40);
        imagettftext($glyph, 16, 0, 4, 30, 0xFFFFFF, $font, $character);
        ob_start();
        try {
            imagepng($glyph);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** Anchor severe landscape crops at the upper third to retain faces and product detail. */
    private function photo(GdImage $canvas, GdImage $source, int $x, int $y, int $width, int $height): void
    {
        $scale = min(imagesx($source) / $width, imagesy($source) / $height);
        $sw = max(1, (int) round($width * $scale));
        $sh = max(1, (int) round($height * $scale));
        $left = intdiv(imagesx($source) - $sw, 2);
        $top = max(0, min(imagesy($source) - $sh, (int) round(imagesy($source) * .30 - $sh * .5)));
        imagecopyresampled($canvas, $source, $x, $y, $left, $top, $width, $height, $sw, $sh);
    }

    private function strip(GdImage $canvas, string $font, int $art, string $brand, string $headline, string $cta, array $palette): void
    {
        $w = imagesx($canvas);
        $h = imagesy($canvas);
        $pad = max(5, (int) round($h * .11));
        $buttonPad = max(5, (int) round(.12 * $h));
        $button = $this->button($cta, $font, (int) ($w * .33) - 2 * $buttonPad, $h - 3 * $pad, min(15, (int) ($h * .18)));
        $buttonWidth = $button['width'] + 2 * $buttonPad;
        $buttonHeight = $button['height'] + 2 * $pad;
        $buttonX = $w - $pad - $buttonWidth;
        $buttonY = intdiv($h - $buttonHeight, 2);
        $x = $art + $pad;
        $textWidth = $buttonX - $pad - $x;

        $brandText = $this->fit($brand, $font, $textWidth, max(12, (int) ($h * .28)), min(15, max(8, (int) ($h * .15))), 1, compact: true);
        $gap = max(3, (int) ($h * .06));
        $heading = $this->fit($headline, $font, $textWidth, $h - 2 * $pad - $brandText['height'] - $gap, min(24, (int) ($h * .29)), 2, compact: true);
        $y = intdiv($h - $brandText['height'] - $gap - $heading['height'], 2);
        $this->draw($canvas, $font, $brandText, $x, $y, $palette['brand']);
        $this->draw($canvas, $font, $heading, $x, $y + $brandText['height'] + $gap, $palette['foreground']);
        imagefilledrectangle($canvas, $buttonX, $buttonY, $buttonX + $buttonWidth - 1, $buttonY + $buttonHeight - 1, $palette['foreground']);
        $this->draw($canvas, $font, $button, $buttonX + $buttonPad, $buttonY + $pad, $palette['background']);
    }

    private function panel(GdImage $canvas, string $font, int $width, int $top, string $brand, string $headline, string $cta, array $palette): void
    {
        $h = imagesy($canvas) - $top;
        $pad = max(8, min(36, (int) round(min($width * .06, $h * .075))));
        $inner = $width - 2 * $pad;
        $gap = max(5, min(22, (int) ($h * .055)));
        $brandText = $this->fit($brand, $font, $inner, (int) ($h * .23), min(20, max(9, (int) ($width * .049))), $width <= 160 ? 4 : 2, compact: true);
        $buttonPad = max(6, (int) ($pad * .75));
        $button = $this->button($cta, $font, $inner - 2 * $buttonPad, (int) ($h * .22), min(18, max(9, (int) ($width * .048))));
        $buttonHeight = $button['height'] + 2 * $buttonPad;
        $heading = $this->fit($headline, $font, $inner, $h - 2 * $pad - $brandText['height'] - 2 * $gap - $buttonHeight, min(46, max(16, (int) ($width * .107))), 4, compact: true);
        $contentHeight = $brandText['height'] + $heading['height'] + $buttonHeight + 2 * $gap;
        $y = $top + intdiv($h - $contentHeight, 2);
        $this->draw($canvas, $font, $brandText, $pad, $y, $palette['brand']);
        $y += $brandText['height'] + $gap;
        $this->draw($canvas, $font, $heading, $pad, $y, $palette['foreground']);
        $y += $heading['height'] + $gap;
        imagefilledrectangle($canvas, $pad, $y, $pad + $button['width'] + 2 * $buttonPad - 1, $y + $buttonHeight - 1, $palette['foreground']);
        $this->draw($canvas, $font, $button, $pad + $buttonPad, $y + $buttonPad, $palette['background']);
    }

    private function button(string $cta, string $font, int $width, int $height, int $start): array
    {
        // Keep short actions on one line before considering a stacked label.
        for ($size = $start; $size >= 8; $size--) {
            if ($this->bounds($font, $size, $cta)['width'] <= $width) {
                return $this->fit($cta, $font, $width, $height, $size, 1);
            }
        }

        return $this->fit($cta, $font, $width, $height, $start, 2);
    }

    /** @return array{size:int,lines:list<string>,width:int,height:int,baseline:int,leading:int} */
    private function fit(string $text, string $font, int $width, int $height, int $start, int $maxLines, bool $compact = false): array
    {
        foreach ($this->labels($text, $compact) as $label) {
            $fit = $this->measureBlock($label, $font, $width, $height, $start, $maxLines);
            if ($fit !== null) {
                return $fit;
            }
        }
        throw new InvalidArgumentException('Approved copy will not fit this display size at a readable font size.');
    }

    /**
     * A narrow brand or headline slot can identify the approved text with a
     * visible ellipsis. Try complete words first. A single long trade name
     * can break at a grapheme boundary. CTA labels never take this path.
     *
     * @return \Generator<int,string>
     */
    private function labels(string $text, bool $compact): \Generator
    {
        yield $text;
        if (! $compact) {
            return;
        }
        while (($space = strrpos($text, ' ')) !== false) {
            $text = substr($text, 0, $space);
            yield rtrim($text, ',;:-').'…';
        }
        preg_match_all('/\X/u', $text, $graphemes);
        $letters = $graphemes[0];
        while (count($letters) > 1) {
            array_pop($letters);
            yield implode('', $letters).'…';
        }
    }

    /** @return array{size:int,lines:list<string>,width:int,height:int,baseline:int,leading:int}|null */
    private function measureBlock(string $text, string $font, int $width, int $height, int $start, int $maxLines): ?array
    {
        $words = explode(' ', $text);
        for ($size = $start; $size >= 8; $size--) {
            $lines = [];
            $current = '';
            foreach ($words as $word) {
                $line = $current === '' ? $word : "{$current} {$word}";
                if ($this->bounds($font, $size, $line)['width'] > $width) {
                    if ($current === '' || $this->bounds($font, $size, $word)['width'] > $width) {
                        continue 2;
                    }
                    $lines[] = $current;
                    $line = $word;
                }
                $current = $line;
            }
            $lines[] = $current;
            if (count($lines) > $maxLines) {
                continue;
            }
            $boxes = array_map(fn ($line) => $this->bounds($font, $size, $line), $lines);
            $baseline = max(array_column($boxes, 'ascent'));
            $lineHeight = max(1, $baseline + max(array_column($boxes, 'descent')) + 1);
            $leading = $lineHeight + max(2, (int) round($size * .22));
            $blockHeight = $lineHeight + (count($lines) - 1) * $leading;
            if ($blockHeight <= $height) {
                return ['size' => $size, 'lines' => $lines, 'width' => max(array_column($boxes, 'width')), 'height' => $blockHeight,
                    'baseline' => $baseline, 'leading' => $leading];
            }
        }

        return null;
    }

    /** @return array{width:int,left:int,ascent:int,descent:int} */
    private function bounds(string $font, int $size, string $text): array
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if (! $box) {
            throw new RuntimeException('FreeType could not measure the supplied text.');
        }
        $left = min($box[0], $box[2], $box[4], $box[6]);

        return ['width' => max($box[0], $box[2], $box[4], $box[6]) - $left + 1, 'left' => $left,
            'ascent' => -min($box[1], $box[3], $box[5], $box[7]), 'descent' => max($box[1], $box[3], $box[5], $box[7])];
    }

    private function draw(GdImage $canvas, string $font, array $text, int $x, int $y, int $color): void
    {
        foreach ($text['lines'] as $i => $line) {
            $left = $this->bounds($font, $text['size'], $line)['left'];
            if (! imagettftext($canvas, $text['size'], 0, $x - $left, $y + $text['baseline'] + $i * $text['leading'], $color, $font, $line)) {
                throw new RuntimeException('FreeType could not render the approved display copy.');
            }
        }
    }
}
