<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Keyword;
use App\Services\SEO\RankTrackingService;
use App\Support\WorkStatus;
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
        public ?string $workRunId = null,
    ) {}

    public function handle(): void
    {
        WorkStatus::update($this->customerId, 'rankings', $this->workRunId, 'running', 'Working on your request.');
        $customer = Customer::find($this->customerId);
        if (! $customer || ! $customer->website) {
            WorkStatus::update($this->customerId, 'rankings', $this->workRunId, 'failed', 'The account or website is no longer available.');

            return;
        }

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
            WorkStatus::update($this->customerId, 'rankings', $this->workRunId, 'failed', 'Add active keywords before running rank tracking.');

            return;
        }

        try {
            $service = new RankTrackingService($customer);
            $results = $service->trackKeywords($keywords, $domain);

            $failed = count(array_filter($results, fn ($result) => $result['failed'] ?? false));
            $tracked = count($results) - $failed;
            WorkStatus::update($this->customerId, 'rankings', $this->workRunId, $failed > 0 ? 'failed' : 'completed', $failed > 0
                ? "{$tracked} keywords measured; {$failed} could not be measured. Previous results are preserved. Retry when ranking data is available."
                : "{$tracked} keywords tracked.");
            Log::info('TrackKeywordRankings: Complete', [
                'customer_id' => $this->customerId,
                'keywords_tracked' => count($results),
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('TrackKeywordRankings: Failed', [
                'customer_id' => $this->customerId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        WorkStatus::update($this->customerId, 'rankings', $this->workRunId, 'failed', 'This request could not finish. Retry using the same form.');
        Log::error('TrackKeywordRankings failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
