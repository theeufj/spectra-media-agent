<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\GoogleAds\ConversionTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyConversionTracking implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 300;

    public function handle(): void
    {
        $customers = Customer::whereNotNull('google_ads_customer_id')
            ->whereHas('campaigns', fn ($q) => $q->where('status', 'active'))
            ->get();

        foreach ($customers as $customer) {
            try {
                $customerId = str_replace('-', '', $customer->google_ads_customer_id);
                $service    = new ConversionTrackingService($customer);
                $count      = $service->getConversionCountLast30Days($customerId);

                if ($count === 0) {
                    Log::warning('VerifyConversionTracking: Zero conversions in last 30 days', [
                        'customer_id' => $customer->id,
                        'name'        => $customer->name,
                    ]);

                    $admins = \App\Models\User::where('is_admin', true)->get();
                    foreach ($admins as $admin) {
                        $admin->notify(new CriticalAgentAlert(
                            'conversion_tracking',
                            "0 conversions recorded in last 30 days for {$customer->name} — conversion tracking may be broken.",
                            ['customer_id' => $customer->id, 'action_required' => 'Check GTM installation and conversion action status']
                        ));
                    }
                } else {
                    $customer->update(['conversion_tracking_verified_at' => now()]);

                    Log::info('VerifyConversionTracking: Healthy', [
                        'customer_id' => $customer->id,
                        'conversions_last_30d' => $count,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('VerifyConversionTracking: Error for customer ' . $customer->id . ': ' . $e->getMessage());
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('VerifyConversionTracking failed: ' . $exception->getMessage());
    }
}
