<?php

namespace App\Jobs\Scheduled;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pull conversions from connected CRMs, including integrations stuck mid-sync.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 *
 * Named for the fan-out, not the work: App\Jobs\SyncCrmConversions is the per-item
 * job this dispatches.
 */
class DispatchCrmConversionSyncs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        \App\Models\CrmIntegration::query()
            ->where(function ($q) {
                $q->whereIn('status', ['connected', 'error'])
                    ->orWhere(function ($q) {
                        $q->where('status', 'syncing')
                            ->where('updated_at', '<', now()->subHours(2));
                    });
            })
            ->each(function ($integration) {
                \App\Jobs\SyncCrmConversions::dispatch($integration->id);
            });
    }
}
