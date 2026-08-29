<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Notifications\ConversionTrackingReady;
use App\Notifications\CriticalAgentAlert;
use App\Services\ConversionSetupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SetupConversionTracking implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 600];

    public $timeout = 120;

    public function __construct(protected Customer $customer) {}

    public function handle(ConversionSetupService $service): void
    {
        // Skip if already set up
        if ($this->customer->conversion_action_id) {
            Log::info('SetupConversionTracking: Already set up, skipping', ['customer_id' => $this->customer->id]);

            return;
        }

        // Waiting for an account is not a failure.
        //
        // A customer either has an account being provisioned or an invitation
        // they have not accepted yet. Neither is something an admin can act on,
        // and treating it as an error meant three retries and a page for every
        // signup while the MCC could not create accounts at all.
        if (! $this->customer->google_ads_customer_id) {
            Log::info('SetupConversionTracking: no Google Ads account yet, will run once there is one', [
                'customer_id' => $this->customer->id,
                'link_status' => $this->customer->google_ads_link_status,
            ]);

            return;
        }

        $result = $service->setup($this->customer);

        if (! $result['success']) {
            Log::error('SetupConversionTracking: Failed', [
                'customer_id' => $this->customer->id,
                'errors' => $result['errors'],
            ]);

            // Notify admins on final failure
            if ($this->attempts() >= $this->tries) {
                CriticalAgentAlert::deliver(
                    'conversion_tracking',
                    'Conversion tracking setup failed: '.$this->customer->name,
                    'Failed to set up conversion tracking for customer: '.$this->customer->name,
                    ['errors' => $result['errors'], 'customer_id' => $this->customer->id],
                    CriticalAgentAlert::RECIPIENTS_ADMINS,
                    $this->customer
                );
            }

            throw new \RuntimeException('Conversion tracking setup failed: '.implode(', ', $result['errors']));
        }

        Log::info('SetupConversionTracking: Complete', [
            'customer_id' => $this->customer->id,
            'resource_name' => $result['resource_name'],
        ]);

        // Notify all users for this customer so they know to install the snippet
        $this->customer->users()->each(fn ($user) => $user->notify(new ConversionTrackingReady($this->customer)));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SetupConversionTracking failed: '.$exception->getMessage(), [
            'customer_id' => $this->customer->id,
        ]);
    }
}
