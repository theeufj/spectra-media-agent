<?php

namespace App\Services\Creative;

use App\Jobs\DeleteCollateralFiles;
use App\Models\ImageCollateral;
use App\Services\StorageHelper;
use RuntimeException;

final class GoogleDisplayBannerSet
{
    /** One package per concept. The parent path is unique to this generation attempt. */
    public function generate(string $image, string $sourcePath, string $brand, string $headline, string $cta, ?string $brandColour = null): array
    {
        $sizes = [];
        $hashes = [];
        $renderer = new GoogleDisplayBannerRenderer;
        try {
            foreach (array_keys(GoogleDisplayBannerRenderer::SIZES) as $size) {
                $bytes = $renderer->render($image, $size, $brand, $headline, $cta, $brandColour);
                $hashes[$size] = hash('sha256', $bytes);
                $path = $sourcePath.'.display/'.$size.'-'.$hashes[$size].'.jpg';
                $sizes[$size] = $path;
                StorageHelper::put($path, $bytes, 'image/jpeg');
            }
        } catch (\Throwable $e) {
            DeleteCollateralFiles::dispatch(array_values($sizes));
            throw $e;
        }

        return ['brand_colour' => $brandColour, 'brand' => $brand, 'headline' => $headline, 'cta' => $cta, 'source_hash' => hash('sha256', $image),
            'sizes' => $sizes, 'hashes' => $hashes];
    }

    /** Verify the actual finished files before their hashes enter a release approval. */
    public function verifiedHashes(ImageCollateral $image, ?string $source = null): array
    {
        $set = $image->google_display_banners;
        if ($set === null) {
            return [];
        }
        if (blank($set['cta'] ?? null) || blank($set['brand'] ?? null) || blank($set['headline'] ?? null)
            || count($set['sizes']) !== count(GoogleDisplayBannerRenderer::SIZES)) {
            throw new RuntimeException('The display banner set is incomplete.');
        }
        if ($source !== null && (! is_string($set['source_hash'] ?? null) || ! hash_equals($set['source_hash'], hash('sha256', $source)))) {
            throw new RuntimeException('The display banner source image changed or is unavailable.');
        }
        $verified = [];
        foreach (GoogleDisplayBannerRenderer::SIZES as $size => [$width, $height]) {
            $path = $set['sizes'][$size] ?? null;
            $hash = $set['hashes'][$size] ?? '';
            if ($path === null || $path !== $image->s3_path.'.display/'.$size.'-'.$hash.'.jpg') {
                throw new RuntimeException("The {$size} display banner has no matching source.");
            }
            $bytes = StorageHelper::get($path, GoogleDisplayBannerRenderer::MAX_BYTES);
            $dimensions = $bytes ? @getimagesizefromstring($bytes) : false;
            if (! $dimensions || [$dimensions[0], $dimensions[1], $dimensions[2]] !== [$width, $height, IMAGETYPE_JPEG]
                || ! hash_equals($hash, hash('sha256', $bytes))) {
                throw new RuntimeException("The {$size} display banner changed or is unavailable.");
            }
            $verified[$size] = $hash;
        }

        return $verified;
    }
}
