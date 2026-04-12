<?php

namespace App\Jobs;

use App\Models\Customer;
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
 * Scrape all viable images from a customer's website pages,
 * download them, store to S3, and dispatch classification jobs.
 */
class HarvestWebsiteAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 600;

    public function __construct(
        protected Customer $customer,
    ) {}

    public function handle(AssetHarvestingService $service): void
    {
        Log::info('HarvestWebsiteAssets: Starting', ['customer_id' => $this->customer->id]);

        $imageUrls = $service->extractImageUrls($this->customer);

        if (empty($imageUrls)) {
            Log::info('HarvestWebsiteAssets: No images found', ['customer_id' => $this->customer->id]);
            return;
        }

        $harvested = 0;

        foreach ($imageUrls as $entry) {
            // Skip if already harvested
            $exists = HarvestedAsset::where('customer_id', $this->customer->id)
                ->where('source_url', $entry['url'])
                ->exists();

            if ($exists) {
                continue;
            }

            $imageData = $service->downloadAndValidate($entry['url']);
            if (!$imageData) {
                continue;
            }

            // Store to S3
            $extension = explode('/', $imageData['mime_type'])[1] ?? 'jpg';
            $extension = match ($extension) {
                'jpeg' => 'jpg',
                default => $extension,
            };
            $filename = uniqid('harvest_', true) . '.' . $extension;
            $storagePath = "harvested/{$this->customer->id}/{$filename}";

            try {
                [$s3Path, $cloudFrontUrl] = StorageHelper::put(
                    $storagePath,
                    $imageData['data'],
                    $imageData['mime_type']
                );
            } catch (\Exception $e) {
                Log::warning('HarvestWebsiteAssets: Upload failed', [
                    'url' => $entry['url'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $asset = HarvestedAsset::create([
                'customer_id' => $this->customer->id,
                'customer_page_id' => $entry['page_id'],
                'source_url' => $entry['url'],
                'source_page_url' => $entry['page_url'],
                's3_path' => $s3Path,
                'cloudfront_url' => $cloudFrontUrl,
                'original_width' => $imageData['width'],
                'original_height' => $imageData['height'],
                'mime_type' => $imageData['mime_type'],
                'file_size' => $imageData['file_size'],
                'status' => 'pending',
            ]);

            // Dispatch classification job with a staggered delay to avoid rate limits
            ClassifyHarvestedAsset::dispatch($asset)->delay(now()->addSeconds($harvested * 3));

            $harvested++;
        }

        Log::info('HarvestWebsiteAssets: Complete', [
            'customer_id' => $this->customer->id,
            'harvested' => $harvested,
            'scanned' => count($imageUrls),
        ]);
    }
}
