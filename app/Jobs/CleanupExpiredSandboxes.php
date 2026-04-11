<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Testing\SyntheticDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupExpiredSandboxes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SyntheticDataService $service): void
    {
        $expired = Customer::sandbox()
            ->where('sandbox_expires_at', '<', now())
            ->get();

        foreach ($expired as $customer) {
            Log::info('Cleaning up expired sandbox', ['customer_id' => $customer->id]);
            $service->deleteSandboxCustomer($customer);
        }

        if ($expired->count() > 0) {
            Log::info("Cleaned up {$expired->count()} expired sandboxes");
        }
    }
}
