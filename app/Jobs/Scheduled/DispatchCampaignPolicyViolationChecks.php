<?php

namespace App\Jobs\Scheduled;

use App\Jobs\CheckCampaignPolicyViolations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ask each platform whether a deployed campaign has been flagged for a policy violation.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 *
 * Named for the fan-out, not the work: App\Jobs\CheckCampaignPolicyViolations is the per-item
 * job this dispatches.
 */
class DispatchCampaignPolicyViolationChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        \App\Models\Campaign::withDeployedPlatforms()->each(function ($campaign) {
            CheckCampaignPolicyViolations::dispatch($campaign->id);
        });
    }
}
