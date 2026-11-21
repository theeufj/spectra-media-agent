<?php

namespace App\Services\Creative;

use App\Models\ImageCollateral;
use App\Services\StorageHelper;
use RuntimeException;

/** Put every exported banner on one sheet at native resolution, including the mobile strips. */
final class DisplayBannerReviewSheet
{
    public static function make(ImageCollateral $image, array $hashes): array
    {
        $order = GoogleDisplayBannerRenderer::SIZES;
        uasort($order, fn ($a, $b) => $b[1] <=> $a[1] ?: $b[0] <=> $a[0]);
        $positions = [];
        $x = $y = $height = 0;
        foreach ($order as $size => [$w, $h]) {
            if ($x + $w + 16 > 2048) {
                $x = 0;
                $y += $height;
                $height = 0;
            }
            $positions[$size] = [$x + 8, $y + 22];
            $x += $w + 16;
            $height = max($height, $h + 35);
        }
        $sheet = imagecreatetruecolor(2048, $y + $height);
        imagefill($sheet, 0, 0, 0xE5E7EB);
        $labels = [];
        foreach ($positions as $size => [$left, $top]) {
            $bytes = StorageHelper::get($image->google_display_banners['sizes'][$size], GoogleDisplayBannerRenderer::MAX_BYTES);
            if (! $bytes || ! hash_equals($hashes[$size], hash('sha256', $bytes)) || ! ($raster = @imagecreatefromstring($bytes))) {
                throw new RuntimeException("The {$size} banner changed during its review.");
            }
            $id = count($labels) + 1;
            $labels[$id] = $size;
            imagestring($sheet, 5, $left, $top - 18, "{$id}. {$size}", 0x111827);
            imagecopy($sheet, $raster, $left, $top, 0, 0, imagesx($raster), imagesy($raster));
            unset($raster);
        }
        ob_start();
        imagejpeg($sheet, null, 94);

        return ['bytes' => (string) ob_get_clean(), 'labels' => $labels];
    }
}
