<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Models\ImageCollateral;
use App\Models\Strategy;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;

final class PerformanceMaxLogo
{
    public const REQUIRED = 'Upload your business logo in the Images section, select it for Performance Max, and approve the reviewed creative. Use a square PNG or JPEG, at least 128 × 128 pixels and no larger than 5 MB.';

    public static function designated(ImageCollateral $image): bool
    {
        return $image->layout === 'logo' || ($image->generation_metadata['role'] ?? null) === 'LOGO';
    }

    public static function digest(ImageCollateral $image, Strategy $strategy): ?string
    {
        $metadata = $image->generation_metadata ?? [];
        if ($image->campaign_id !== $strategy->campaign_id || ($image->strategy_id !== null && $image->strategy_id !== $strategy->id)
            || $image->layout !== 'logo' || ($metadata['role'] ?? null) !== 'LOGO') {
            return null;
        }
        $sourceVerified = match ($metadata['provider'] ?? null) {
            'customer_upload' => $image->source === 'uploaded',
            'first_party_asset' => filled($metadata['source_public_path'] ?? null) && self::validDigest($metadata['source_sha256'] ?? null),
            default => false,
        };

        return $sourceVerified && self::validDigest($metadata['sha256'] ?? null) ? $metadata['sha256'] : null;
    }

    /** A logo has its own dimensions and provenance as well as its own reviewed role. */
    public static function imageField(ImageCollateral $image, Strategy $strategy, string $bytes, bool $duringReview = false): ?int
    {
        if ($image->campaign_id !== $strategy->campaign_id || ($image->strategy_id !== null && $image->strategy_id !== $strategy->id)) {
            return null;
        }
        if (! self::designated($image)) {
            return PerformanceMaxAssetSpec::imageField($bytes);
        }
        $digest = self::digest($image, $strategy);
        if ($digest === null || ! hash_equals($digest, hash('sha256', $bytes)) || strlen($bytes) > 5 * 1024 * 1024) {
            return null;
        }
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] !== $size[1] || $size[0] < 128 || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }
        if (! $duringReview && (($strategy->creative_approval['logo_hashes'][$image->id] ?? null) !== $digest
            || ($strategy->creative_approval['image_hashes'][$image->id] ?? null) !== $digest)) {
            return null;
        }

        return AssetFieldType::LOGO;
    }

    private static function validDigest(mixed $digest): bool
    {
        return is_string($digest) && preg_match('/^[0-9a-f]{64}$/D', $digest) === 1;
    }
}
