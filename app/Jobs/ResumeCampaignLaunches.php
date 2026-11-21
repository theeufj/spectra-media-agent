<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Services\Deployment\CampaignLaunchIntent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ResumeCampaignLaunches implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $uniqueFor = 300;

    public function __construct(public ?int $customerId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->customerId ?? 'all');
    }

    public function handle(CampaignLaunchIntent $launch): void
    {
        app(\App\Services\Billing\LaunchFunding::class)->releaseClosed($this->customerId);

        // Recover a worker or web process that died after marking the intent queued
        // but before the job was dispatched and claimed. Started jobs have platform
        // reconciliation; replaying one here could create a second campaign.
        $waiting = Strategy::whereNotNull('launch_intent')
            ->whereHas('campaign', fn ($query) => $query->when($this->customerId !== null, fn ($q) => $q->where('customer_id', $this->customerId)));
        (clone $waiting)->whereNotNull('launch_intent->campaign_create')->where('launch_intent->version', 2)
            ->whereIn('deployment_status', ['queued', 'deploying', 'failed'])->where('updated_at', '<', now()->subMinutes(35))
            ->eachById(function (Strategy $strategy) {
                try {
                    app(\App\Services\Deployment\SubmittedCampaign::class)->reconcile($strategy);
                } catch (\Throwable $failure) {
                    report($failure);
                }
            });
        $stale = (clone $waiting)->where('deployment_status', 'queued')
            ->whereNull('launch_intent->started_at')->where('updated_at', '<', now()->subMinutes(35));
        $stale->update(['deployment_status' => CampaignLaunchIntent::WAITING]);
        (clone $waiting)->where('deployment_status', 'queued')
            ->whereNotNull('launch_intent->started_at')->where('updated_at', '<', now()->subMinutes(35))
            ->update(['deployment_status' => 'failed',
                'deployment_error' => 'Deployment stopped before these ads were submitted. Check the current account and campaign status before trying again.']);

        (clone $waiting)->where('deployment_status', CampaignLaunchIntent::WAITING)->select(['id', 'campaign_id', 'launch_intent'])
            ->chunkById(100, function ($strategies) use ($launch): void {
                foreach ($strategies->unique(fn (Strategy $strategy) => $strategy->launch_intent['id']) as $strategy) {
                    try {
                        $launch->resume($strategy->campaign_id, $strategy->launch_intent['id']);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
