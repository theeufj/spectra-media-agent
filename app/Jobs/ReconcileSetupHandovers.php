<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Keep pending customer acceptance moving without a staff handover button. */
class ReconcileSetupHandovers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 60;

    public function handle(): void
    {
        Customer::where('service_type', 'setup_only')->whereNotNull('setup_fee_paid_at')
            ->where(fn ($query) => $query->whereNull('handover_at')->orWhereNull('setup_handover->completed_at'))->where('is_sandbox', false)
            ->whereNotNull('google_ads_customer_id')->whereNotNull('setup_handover->google_identity->email')
            ->chunkById(100, function ($customers) {
                foreach ($customers as $customer) {
                    InvitePaidSetupCustomer::dispatchIfReady($customer);
                }
            });
    }
}
