<?php

namespace App\Jobs;

use App\Models\HarvestedAsset;
use App\Services\AssetHarvestingService;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process a classified harvested asset:
 * 1. Background removal for product shots
 * 2. Generate platform-specific variants (landscape, square, vertical)
 */
class ProcessHarvestedAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 600;

    public function __construct(
        protected HarvestedAsset $asset,
    ) {}

    public function handle(AssetHarvestingService $service): void
    {
        Log::info('ProcessHarvestedAsset: Starting', [
            'asset_id' => $this->asset->id,
            'classification' => $this->asset->classification,
        ]);

        $imageData = StorageHelper::get($this->asset->s3_path);
        if (!$imageData) {
            $this->asset->update(['status' => 'failed']);
            return;
        }

        $base64 = base64_encode($imageData);
        $mimeType = $this->asset->mime_type;
        $customerId = $this->asset->customer_id;

        // Step 1: Background removal for product shots
        if ($this->asset->classification === 'product') {
            $bgRemoved = $service->removeBackground($base64, $mimeType);

            if ($bgRemoved && isset($bgRemoved['data'])) {
                $decodedBg = base64_decode($bgRemoved['data']);
                $bgExt = $this->getExtension($bgRemoved['mimeType'] ?? 'image/png');
                $bgPath = "harvested/{$customerId}/bg_removed_" . uniqid('', true) . ".{$bgExt}";

                try {
                    [$bgS3, $bgUrl] = StorageHelper::put($bgPath, $decodedBg, $bgRemoved['mimeType'] ?? 'image/png');
                    $this->asset->update([
                        'bg_removed_s3_path' => $bgS3,
                        'bg_removed_url' => $bgUrl,
                    ]);

                    Log::info('ProcessHarvestedAsset: Background removed', ['asset_id' => $this->asset->id]);

                    // Use bg-removed version for variant generation
                    $base64 = $bgRemoved['data'];
                    $mimeType = $bgRemoved['mimeType'] ?? 'image/png';
                } catch (\Exception $e) {
                    Log::warning('ProcessHarvestedAsset: BG removal upload failed', [
                        'asset_id' => $this->asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Step 2: Generate platform variants
        $brandColors = $this->getBrandColors();
        $variants = [];

        foreach (['landscape', 'square', 'vertical'] as $format) {
            // Check if the original already suits this format
            $details = $this->asset->classification_details ?? [];
            $recommendedKey = match ($format) {
                'landscape' => 'landscape_1200x628',
                'square' => 'square_1080x1080',
                'vertical' => 'vertical_1080x1920',
            };

            // Skip if classification says this crop isn't recommended
            $recommended = $details['recommended_crops'][$recommendedKey] ?? true;
            if (!$recommended && $format !== 'landscape') {
                // Always generate landscape; skip others only if not recommended
                continue;
            }

            $variant = $service->generateVariant($base64, $mimeType, $format, $brandColors);

            if ($variant && isset($variant['data'])) {
                $decoded = base64_decode($variant['data']);
                $ext = $this->getExtension($variant['mimeType'] ?? 'image/jpeg');
                $varPath = "harvested/{$customerId}/{$format}_" . uniqid('', true) . ".{$ext}";

                try {
                    [$varS3, $varUrl] = StorageHelper::put($varPath, $decoded, $variant['mimeType'] ?? 'image/jpeg');
                    $variants[$format] = [
                        's3_path' => $varS3,
                        'url' => $varUrl,
                        'mime_type' => $variant['mimeType'] ?? 'image/jpeg',
                    ];
                } catch (\Exception $e) {
                    Log::warning("ProcessHarvestedAsset: {$format} variant upload failed", [
                        'asset_id' => $this->asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Brief pause between Gemini calls
            sleep(2);
        }

        $this->asset->update([
            'variants' => $variants,
            'status' => 'processed',
        ]);

        Log::info('ProcessHarvestedAsset: Complete', [
            'asset_id' => $this->asset->id,
            'variants_generated' => count($variants),
        ]);
    }

    protected function getBrandColors(): array
    {
        $customer = $this->asset->customer;
        if (!$customer || !$customer->brandGuideline) {
            return [];
        }

        $palette = $customer->brandGuideline->color_palette;
        if (!is_array($palette)) {
            return [];
        }

        return array_filter([
            $palette['primary'] ?? null,
            $palette['secondary'] ?? null,
        ]);
    }

    protected function getExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'png',
        };
    }
}
