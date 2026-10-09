<?php

namespace App\Jobs;

use App\Models\Customer;
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

        try {
            $service = new RankTrackingService($customer);
            $result = $service->trackOrganicQueries();
            WorkStatus::update($this->customerId, 'rankings', $this->workRunId, $result['success'] ? 'completed' : 'failed', $result['success']
                ? $result['tracked'].' organic queries measured over the last complete 28-day reporting window. '.($result['warning'] ?? '')
                : $result['error']);
            Log::info('TrackKeywordRankings: Complete', [
                'customer_id' => $this->customerId, 'success' => $result['success'],
                'queries_measured' => $result['tracked'] ?? 0,
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
