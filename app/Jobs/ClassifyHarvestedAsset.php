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
 * Classify a harvested image using Gemini Vision.
 * If ad-suitable, dispatch ProcessHarvestedAsset for background removal + resizing.
 */
class ClassifyHarvestedAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 120;

    public function __construct(
        protected HarvestedAsset $asset,
    ) {}

    public function handle(AssetHarvestingService $service): void
    {
        Log::info('ClassifyHarvestedAsset: Starting', ['asset_id' => $this->asset->id]);

        // Read the stored image
        $imageData = StorageHelper::get($this->asset->s3_path);
        if (!$imageData) {
            Log::warning('ClassifyHarvestedAsset: Could not read stored image', ['asset_id' => $this->asset->id]);
            $this->asset->update(['status' => 'failed']);
            return;
        }

        $base64 = base64_encode($imageData);
        $result = $service->classifyImage($base64, $this->asset->mime_type);

        if (!$result || !isset($result['classification'])) {
            Log::warning('ClassifyHarvestedAsset: Classification failed', ['asset_id' => $this->asset->id]);
            $this->asset->update(['status' => 'failed']);
            return;
        }

        $this->asset->update([
            'classification' => $result['classification'],
            'classification_details' => $result,
            'status' => 'classified',
        ]);

        Log::info('ClassifyHarvestedAsset: Classified', [
            'asset_id' => $this->asset->id,
            'classification' => $result['classification'],
            'ad_suitable' => $result['ad_suitable'] ?? false,
        ]);

        // Processing (bg removal + variants) is now on-demand when user clicks "Use".
        // This saves 4 Gemini image-gen calls per asset that the user never selects.
    }
}
