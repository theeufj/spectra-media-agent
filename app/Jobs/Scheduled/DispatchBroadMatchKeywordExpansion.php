<?php

namespace App\Jobs\Scheduled;

use App\Jobs\ExpandBroadMatchKeywords;
use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Look for broad-match expansion opportunities on deployed search campaigns.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 *
 * Named for the fan-out, not the work: App\Jobs\ExpandBroadMatchKeywords is the per-item
 * job this dispatches.
 */
class DispatchBroadMatchKeywordExpansion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        Campaign::withDeployedPlatforms()
            ->whereNotNull('google_ads_campaign_id')
            ->each(function ($campaign) {
                ExpandBroadMatchKeywords::dispatch($campaign);
            });
    }
}
