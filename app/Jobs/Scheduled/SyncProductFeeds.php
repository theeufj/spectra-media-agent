<?php

namespace App\Jobs\Scheduled;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-sync product feeds that are due according to their configured frequency.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 */
class SyncProductFeeds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        \App\Models\ProductFeed::where('status', 'active')
            ->where(function ($q) {
                $q->where('sync_frequency', 'hourly')
                    ->orWhere(function ($q2) {
                        $q2->where('sync_frequency', 'daily')
                            ->where(function ($q3) {
                                $q3->whereNull('last_synced_at')
                                    ->orWhere('last_synced_at', '<', now()->subHours(20));
                            });
                    });
            })
            ->each(function ($feed) {
                \App\Jobs\SyncProductFeed::dispatch($feed->id);
            });
    }
}
