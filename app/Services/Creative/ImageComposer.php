<?php

namespace App\Services\Creative;

use Illuminate\Support\Facades\Log;

class ImageComposer
{
    public function layout(string $platform, int $slot): string
    {
        // Search image assets ship without graphic or text overlays.
        if (preg_match('/search|sem|^google(?: ads)?$/i', trim($platform))) {
            return 'clean';
        }

        return ['clean', 'headline', 'signature'][$slot % 3];
    }

    public function compose(\Intervention\Image\Interfaces\ImageInterface $image, string $layout, ?string $headline, ?string $brand): void
    {
        if ($layout === 'headline' && $headline) {
            $this->drawHeadline($image, $headline, $this->resolveFont());
        }
        if ($layout === 'signature' && $brand && ($font = $this->resolveFont())) {
            $margin = (int) ($image->width() * 0.08);
            $size = max(12, (int) ($image->height() * 0.035));
            $fitted = $this->fitHeadline($brand, $font, $image->width() - 2 * $margin, $size, 1);
            if (! $fitted) {
                return;
            }
            $ink = $this->regionIsLight($image, $margin, $image->height() - 2 * $margin, $image->width() - 2 * $margin, $margin) ? '101828' : 'ffffff';
            $image->text($brand, $margin, $image->height() - $margin, function ($text) use ($font, $fitted, $ink) {
                $text->filename($font);
                $text->size($fitted['size']);
                $text->color($ink);
                $text->align('left');
                $text->valign('bottom');
            });
        }
    }

    public function fitHeadline(string $text, string $fontPath, int $maxWidth, int $startSize, int $maxLines = 2): ?array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];

        if ($words === []) {
            return null;
        }

        $widthAt = function (string $s, int $size) use ($fontPath): int {
            $box = imagettfbbox($size, 0, $fontPath, $s);

            return $box === false ? PHP_INT_MAX : (int) abs($box[2] - $box[0]);
        };

        // Shrink until the words can be arranged inside maxLines lines that
        // each fit. Floor at a size that is still legible as a thumbnail;
        // below it, no headline beats an unreadable one.
        for ($size = $startSize; $size >= (int) ($startSize * 0.45); $size -= 2) {
            $lines = [];
            $current = '';

            foreach ($words as $word) {
                if ($widthAt($word, $size) > $maxWidth) {
                    // A single word wider than the frame cannot be wrapped out
                    // of trouble; only a smaller size helps.
                    $lines = [];
                    break;
                }

                $candidate = $current === '' ? $word : $current.' '.$word;

                if ($widthAt($candidate, $size) <= $maxWidth) {
                    $current = $candidate;

                    continue;
                }

                $lines[] = $current;
                $current = $word;
            }

            if ($lines === [] && $current === '') {
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            if (count($lines) <= $maxLines) {
                return ['lines' => $lines, 'size' => $size];
            }
        }

        return null;
    }

    /**
     * Whether the area the headline will occupy is light or dark.
     *
     * The prompt asks for an uncluttered corner, not a white one, and the
     * photographs come back with brick, glass, sunlight and shadow up there.
     * Sampling decides the ink colour instead of guessing it: a fixed white
     * headline disappears against a bright wall and a fixed dark one
     * disappears against a shadow.
     */
    public function regionIsLight(\Intervention\Image\Interfaces\ImageInterface $img, int $x, int $y, int $w, int $h): bool
    {
        $total = 0;
        $samples = 0;

        // A coarse grid is plenty — this decides one bit.
        for ($i = 1; $i <= 4; $i++) {
            for ($j = 1; $j <= 3; $j++) {
                $px = min($img->width() - 1, max(0, $x + (int) ($w * $i / 5)));
                $py = min($img->height() - 1, max(0, $y + (int) ($h * $j / 4)));

                try {
                    $c = $img->pickColor($px, $py)->toArray();
                } catch (\Throwable) {
                    continue;
                }

                // Rec. 601 luma: green carries most of perceived brightness.
                $total += 0.299 * ($c[0] ?? 0) + 0.587 * ($c[1] ?? 0) + 0.114 * ($c[2] ?? 0);
                $samples++;
            }
        }

        return $samples === 0 ? true : ($total / $samples) > 140;
    }

    /**
     * Draw the approved headline onto the artwork.
     *
     * Set by us, at a measured size, inside a safe area — rather than asked
     * for in the prompt and cropped by the model. It is also the same words in
     * the same place across the square, landscape and MREC crops of one
     * picture, which three separate renderings by the model could never be.
     */
    public function drawHeadline(\Intervention\Image\Interfaces\ImageInterface $img, string $headline, ?string $fontPath): void
    {
        if ($fontPath === null || ! function_exists('imagettfbbox')) {
            return;
        }

        $w = $img->width();
        $h = $img->height();

        // The safe area the prompt reserves: inset from every edge, and clear
        // of the bottom where a brand banner may be composited.
        $margin = (int) ($w * 0.08);
        $maxWidth = $w - ($margin * 2);

        $fitted = $this->fitHeadline($headline, $fontPath, $maxWidth, (int) ($h * 0.085));

        if ($fitted === null) {
            Log::warning('Headline could not be fitted and was left off the creative', [
                'headline' => $headline,
                'width' => $w,
            ]);

            return;
        }

        $size = $fitted['size'];
        $lineHeight = (int) ($size * 1.24);
        $blockHeight = $lineHeight * count($fitted['lines']);
        $top = (int) ($h * 0.075);

        $light = $this->regionIsLight($img, $margin, $top, $maxWidth, $blockHeight);
        $ink = $light ? '101828' : 'ffffff';
        $shadow = $light ? 'rgba(255, 255, 255, 0.55)' : 'rgba(0, 0, 0, 0.45)';

        foreach ($fitted['lines'] as $i => $line) {
            $y = $top + ($i * $lineHeight) + (int) ($size * 0.85);

            // A soft counter-coloured offset keeps it readable where the
            // photograph turns out busier than the brief asked for.
            foreach ([[2, 2, $shadow], [0, 0, $ink]] as [$dx, $dy, $colour]) {
                $img->text($line, $margin + $dx, $y + $dy, function ($font) use ($fontPath, $size, $colour) {
                    $font->filename($fontPath);
                    $font->size($size);
                    $font->color($colour);
                    $font->align('left');
                    $font->valign('bottom');
                });
            }
        }
    }

    public function resolveFont(): ?string
    {
        $candidates = [
            /*
             * Bundled first, so the typography is the same everywhere.
             *
             * This list was entirely system paths, so which face a headline
             * was set in depended on what the box happened to have installed —
             * and on a machine with none, the text was silently left off. It
             * also meant the tests covering the fitting arithmetic skipped
             * rather than ran, locally and in CI both, which is the same as
             * not having them. DejaVu is freely redistributable; the system
             * paths stay as a fallback.
             */
            public_path('fonts/DejaVuSans-Bold.ttf'),
            public_path('fonts/Arial.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
