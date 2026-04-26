<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\ConversionSetupService;
use App\Notifications\CriticalAgentAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SetupConversionTracking implements ShouldQueue
{
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

        $result = $service->setup($this->customer);

        if (!$result['success']) {
            Log::error('SetupConversionTracking: Failed', [
                'customer_id' => $this->customer->id,
                'errors'      => $result['errors'],
            ]);

            // Notify admins on final failure
            if ($this->attempts() >= $this->tries) {
                $admins = \App\Models\User::where('is_admin', true)->get();
                foreach ($admins as $admin) {
                    $admin->notify(new CriticalAgentAlert(
                        'conversion_tracking',
                        'Failed to set up conversion tracking for customer: ' . $this->customer->name,
                        ['errors' => $result['errors'], 'customer_id' => $this->customer->id]
                    ));
                }
            }

            throw new \RuntimeException('Conversion tracking setup failed: ' . implode(', ', $result['errors']));
        }

        Log::info('SetupConversionTracking: Complete', [
            'customer_id'   => $this->customer->id,
            'resource_name' => $result['resource_name'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SetupConversionTracking failed: ' . $exception->getMessage(), [
            'customer_id' => $this->customer->id,
        ]);
    }
}
