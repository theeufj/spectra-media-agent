<?php

namespace App\Jobs\Scheduled;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Alert admins about campaigns sitting in the manual deployment queue past the 24 hours the customer was promised.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 */
class PendingAdminDeploymentOverdue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        \App\Models\Campaign::where('status', \App\Enums\CampaignStatus::PendingAdminDeployment)
            ->where('pending_admin_deployment_at', '<', now()->subDay())
            ->with('customer')
            ->get()
            ->each(function ($campaign) {
                \App\Notifications\CriticalAgentAlert::deliver(
                    'pending_admin_deployment_overdue',
                    "OVERDUE: \"{$campaign->name}\" still waiting for admin deployment",
                    'This campaign has been in the manual deployment queue for over 24 hours — the customer was promised launch within a day.',
                    [
                        'campaign_id' => $campaign->id,
                        'customer_id' => $campaign->customer_id,
                        'queued_at' => (string) $campaign->pending_admin_deployment_at,
                    ],
                    \App\Models\NotificationTemplate::RECIPIENTS_ADMINS,
                    $campaign->customer
                );
            });
    }
}
