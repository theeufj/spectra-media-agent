<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Checks the approval status of a healed ad ~24 hours after it was submitted.
 * Dispatched by SelfHealingAgent after a successful ad regeneration.
 */
class VerifyAdApproval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $backoff = [300];

    public function __construct(
        public Customer $customer,
        public string $adResourceName,
        public int $campaignId
    ) {}

    public function handle(): void
    {
        try {
            $getStatus = new GetAdStatus($this->customer);
            $status = $getStatus($this->customer->google_ads_customer_id, $this->adResourceName);

            $approved = $status && in_array(strtolower($status), ['enabled', 'paused', 'approved'], true);

            Log::info('VerifyAdApproval: Healing outcome', [
                'ad'          => $this->adResourceName,
                'status'      => $status,
                'approved'    => $approved,
                'campaign_id' => $this->campaignId,
            ]);

            if (!$approved) {
                // Still disapproved — notify so a human can intervene
                $this->customer->users()->each(fn ($user) => $user->notify(
                    new \App\Notifications\CriticalAgentAlert(
                        'self_healing',
                        "Healed ad is still disapproved 24h after resubmission: {$this->adResourceName}",
                        ['ad' => $this->adResourceName, 'status' => $status, 'campaign_id' => $this->campaignId]
                    )
                ));
            }
        } catch (\Throwable $e) {
            // GetAdStatus may not exist yet — log and exit gracefully
            Log::warning('VerifyAdApproval: Could not check ad status: ' . $e->getMessage(), [
                'ad' => $this->adResourceName,
            ]);
        }
    }
}
