<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Keyword;
use App\Services\SEO\RankTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TrackKeywordRankings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        public int $customerId,
    ) {}

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);
        if (!$customer || !$customer->website) return;

        $domain = parse_url($customer->website, PHP_URL_HOST) ?: $customer->website;

        // Get keywords to track from the customer's keyword list
        $keywords = Keyword::where('customer_id', $this->customerId)
            ->active()
            ->pluck('keyword_text')
            ->unique()
            ->take(50) // Limit to 50 keywords per tracking run
            ->toArray();

        if (empty($keywords)) {
            Log::info('TrackKeywordRankings: No keywords to track', ['customer_id' => $this->customerId]);
            return;
        }

        try {
            $service = new RankTrackingService($customer);
            $results = $service->trackKeywords($keywords, $domain);

            Log::info('TrackKeywordRankings: Complete', [
                'customer_id' => $this->customerId,
                'keywords_tracked' => count($results),
            ]);
        } catch (\Exception $e) {
            Log::error('TrackKeywordRankings: Failed', [
                'customer_id' => $this->customerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrackKeywordRankings failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
