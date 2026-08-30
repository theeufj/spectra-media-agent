<?php

namespace App\Jobs\Scheduled;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Retry offline conversion uploads that are pending or failed below the attempt cap.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 */
class RetryOfflineConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        \App\Models\OfflineConversion::query()
            ->where(function ($q) {
                $q->where('upload_status', 'pending')
                    ->orWhere(function ($q) {
                        $q->where('upload_status', 'failed')
                            ->where('upload_attempts', '<', \App\Jobs\UploadOfflineConversions::MAX_ATTEMPTS);
                    });
            })
            ->distinct()
            ->pluck('customer_id')
            ->each(function ($customerId) {
                \App\Jobs\UploadOfflineConversions::dispatch($customerId);
            });
    }
}
