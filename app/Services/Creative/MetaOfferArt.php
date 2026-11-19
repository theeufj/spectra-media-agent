<?php

namespace App\Services\Creative;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\StorageHelper;
use Intervention\Image\ImageManager;

/** Render digital offers from supplied copy, brand and owned media without inventing product UI. */
class MetaOfferArt
{
    public function __construct(private ImageComposer $composer) {}

    public static function applies(Campaign $campaign, Strategy $strategy, string $platform): bool
    {
        if (! preg_match('/facebook|instagram|\bmeta\b/i', $platform)) {
            return false;
        }
        $offer = $campaign->product_focus ?: ($campaign->customer?->description ?: $strategy->ad_copy_strategy);

        return (bool) preg_match('/\b(?:saas|software|AI agents?|web apps?|web applications?|digital platforms?|(?:ad|advertising|automation) platforms?|online platforms?|software-as-a-service)\b/i', (string) $offer.' '.($campaign->customer->industry ?? ''));
    }

    /** @return array{id:int,bytes:string}|null */
    public function campaignSource(Campaign $campaign, int $variant): ?array
    {
        $seeds = ImageCollateral::where('campaign_id', $campaign->id)->where('is_seed', true)->where('is_active', true)
            ->whereNotNull('s3_path')->orderBy('id')->get(['id', 's3_path']);
        for ($offset = 0; $offset < $seeds->count(); $offset++) {
            $seed = $seeds[($variant + $offset) % $seeds->count()];
            $bytes = StorageHelper::get($seed->s3_path, 12 * 1024 * 1024);
            if ($bytes && @getimagesizefromstring($bytes)) {
                return ['id' => $seed->id, 'bytes' => $bytes];
            }
        }

        return null;
    }

    /** The source, when supplied, is an existing campaign asset. Keep the entire original intact. */
    public function render(Campaign $campaign, string $headline, ?string $detail, int $width, int $height, int $variant = 0, ?string $source = null): string
    {
        $customer = $campaign->customer;
        $brand = trim((string) $customer?->name);
        $font = $this->composer->resolveFont();
        if (! $font || $brand === '' || trim($headline) === '') {
            throw new \RuntimeException('The Meta offer layout requires the customer brand, ad copy and a usable font.');
        }
        $colours = $customer->brandGuideline?->color_palette['primary_colors'] ?? [];
        $accent = '#16324f';
        foreach ($colours as $colour) {
            if (is_string($colour) && preg_match('/^#?[a-f0-9]{6}$/i', $colour)) {
                $accent = '#'.ltrim($colour, '#');
                break;
            }
        }
        $background = [$accent, '#faf9f6', '#141821'][$variant % 3];
        $manager = ImageManager::gd();
        $canvas = $manager->create($width, $height)->fill($background);
        if ($background !== $accent) {
            $canvas->drawRectangle(0, 0, function ($shape) use ($variant, $width, $height, $accent) {
                $shape->size($variant % 3 === 1 ? (int) ($width * .025) : $width, $variant % 3 === 1 ? $height : (int) ($height * .025));
                $shape->background($accent);
            });
        }
        [$r, $g, $b] = array_map(static function ($hex) {
            $v = hexdec($hex) / 255;

            return $v <= .04045 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4;
        }, str_split(substr($background, 1), 2));
        $ink = .2126 * $r + .7152 * $g + .0722 * $b > .179 ? '#000000' : '#ffffff';
        $margin = (int) ($width * .075);
        $lineWidth = $width - 2 * $margin;
        $size = fn (float $fraction) => max(10, (int) (min($width, $height * 1.24) * $fraction));
        $write = function (string $text, int $y, int $startSize, int $maxLines, int $maxWidth, int $bottom) use ($canvas, $font, $ink, $margin): ?int {
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            $fit = $this->composer->fitHeadline($text, $font, $maxWidth, $startSize, $maxLines);
            if (! $fit || implode(' ', $fit['lines']) !== $text) {
                return null;
            }
            $leading = (int) ceil($fit['size'] * 1.42);
            if ($y + count($fit['lines']) * $leading > $bottom) {
                return null;
            }
            foreach ($fit['lines'] as $line) {
                $canvas->text($line, $margin, $y, function ($type) use ($font, $fit, $ink) {
                    $type->filename($font);
                    $type->size($fit['size']);
                    $type->color($ink);
                    $type->valign('top');
                });
                $y += $leading;
            }

            return $y;
        };
        $top = (int) ($height * .10);
        $brandEnd = $write($brand, $top, $size(.049), 2, $lineWidth, (int) ($height * .28));
        if ($brandEnd === null) {
            throw new \RuntimeException('The Meta brand name cannot be rendered legibly.');
        }
        $headingY = max($brandEnd + $size(.04), (int) ($height * .32));
        $copyWidth = $lineWidth;
        if ($source !== null) {
            $picture = $manager->read($source);
            if ($width > $height) {
                $copyWidth = (int) ($lineWidth * .57);
                $picture->scaleDown(width: (int) ($lineWidth * .36), height: (int) ($height * .68));
                $canvas->place($picture, 'right', $margin);
            } else {
                $picture->scaleDown(width: $lineWidth, height: (int) ($height * .25));
                $pictureY = $brandEnd + $size(.035);
                $canvas->place($picture, 'top-left', $margin, $pictureY);
                $headingY = $pictureY + $picture->height() + $size(.035);
            }
        }
        $headingLimit = (int) ($height * .81);
        $after = $write($headline, $headingY, $size(.09), 4, $copyWidth, $headingLimit);
        if ($after === null) {
            $after = $write($headline, $headingY, $size(.06), 5, $copyWidth, $headingLimit);
        }
        if ($after === null) {
            throw new \RuntimeException('The selected Meta headline does not fit this placement. Generate a shorter headline.');
        }
        if ($detail && $detail !== $headline && $width >= 500) {
            $write($detail, $after + $size(.024), $size(.033), 3, $copyWidth, (int) ($height * .83));
        }
        if ($domain = parse_url((string) $customer->website, PHP_URL_HOST)) {
            $write($domain, (int) ($height * .86), $size(.029), 1, $lineWidth, (int) ($height * .94));
        }

        return (string) $canvas->toPng();
    }
}
