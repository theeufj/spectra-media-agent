<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Models\AdCopy;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;

final class PerformanceMaxAssetSpec
{
    /** @return array<int, list<string>> */
    public static function text(AdCopy $copy): array
    {
        $headlines = self::selectText($copy->headlines ?? [], 1, 30, 15);
        $descriptions = self::selectText($copy->descriptions ?? [], 1, 90, 5);
        // Older approved copy did not store long headlines. Google accepts a
        // complete sentence from that reviewed copy as the long text asset.
        $longHeadlines = $copy->long_headlines === null
            ? self::selectText($descriptions, 31, 90, 1)
            : self::selectText($copy->long_headlines, 31, 90, 5);

        return [
            AssetFieldType::HEADLINE => $headlines,
            AssetFieldType::LONG_HEADLINE => $longHeadlines,
            AssetFieldType::DESCRIPTION => $descriptions,
        ];
    }

    public static function imageField(string $bytes): ?int
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || strlen($bytes) > 5 * 1024 * 1024 || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            return null;
        }

        [$width, $height] = $size;
        $ratio = $width / $height;

        return match (true) {
            $ratio >= 1.8145 && $ratio <= 2.0055 && $width >= 600 && $height >= 314 => AssetFieldType::MARKETING_IMAGE,
            $ratio >= 0.95 && $ratio <= 1.05 && $width >= 300 && $height >= 300 => AssetFieldType::SQUARE_MARKETING_IMAGE,
            $ratio >= 0.76 && $ratio <= 0.84 && $width >= 480 && $height >= 600 => AssetFieldType::PORTRAIT_MARKETING_IMAGE,
            default => null,
        };
    }

    public static function textKey(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /** @return list<string> */
    private static function selectText(array $assets, int $minLength, int $maxLength, int $limit): array
    {
        $selected = [];
        foreach ($assets as $text) {
            if (is_string($text)) {
                $text = trim($text);
                $length = mb_strlen($text);
                if ($length >= $minLength && $length <= $maxLength) {
                    $selected[self::textKey($text)] ??= $text;
                }
            }
        }

        return array_slice(array_values($selected), 0, $limit);
    }
}
