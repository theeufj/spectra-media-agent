<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Customers\HandOverAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Invite a paid setup customer as soon as their own Google Ads account exists. */
class InvitePaidSetupCustomer implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deleteWhenMissingModels = true;

    public $tries = 3;

    public $timeout = 180;

    public $uniqueFor = 600;

    public function uniqueId(): string
    {
        return (string) $this->customer->id;
    }

    public $backoff = [60, 300];

    public function __construct(public Customer $customer) {}

    public static function dispatchIfReady(Customer $customer): void
    {
        if ($customer->isPaidSetupOnly() && ! $customer->is_sandbox && $customer->google_ads_customer_id) {
            self::dispatch($customer);
        }
    }

    public function handle(): void
    {
        $customer = $this->customer->fresh();
        if (! $customer || ! $customer->isPaidSetupOnly() || $customer->is_sandbox || ! $customer->google_ads_customer_id) {
            return;
        }

        app(HandOverAccount::class)->handOver($customer);
    }
}
