<?php

namespace App\Jobs;

use App\Models\ProductFeed;
use App\Services\GoogleAds\MerchantCenterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProductFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $productFeedId
    ) {}

    public function handle(): void
    {
        $feed = ProductFeed::find($this->productFeedId);
        if (!$feed) return;

        try {
            $feed->update(['status' => 'processing']);

            if ($feed->source_type !== 'api') {
                throw new \RuntimeException("Source type '{$feed->source_type}' is not yet supported. Only Merchant Center API is available.");
            }

            $service = new MerchantCenterService($feed->customer);
            $synced = $service->syncToDatabase($feed);

            $feed->update([
                'status' => 'active',
                'last_error' => null,
            ]);

            Log::info('SyncProductFeed: Completed', [
                'feed_id' => $feed->id,
                'products_synced' => $synced,
            ]);
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $feed->update([
                'status' => 'error',
                'last_error' => strlen($errorMsg) > 500 ? substr($errorMsg, 0, 500) . '...' : $errorMsg,
            ]);
            Log::error('SyncProductFeed: Failed', [
                'feed_id' => $feed->id,
                'error' => $errorMsg,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncProductFeed failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
