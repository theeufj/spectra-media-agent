<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\GoogleAds\CreateAndLinkManagedAccount;
use App\Services\GoogleAds\CreateManagedAccount;
use App\Services\GoogleAds\CreateCustomerClientLink;
use Illuminate\Support\Facades\Log;

class CreateGoogleAdsSubAccount extends Command
{
    protected $signature = 'googleads:create-subaccount 
                            {customer_id : The Laravel customer ID}
                            {--name= : Custom account name (optional)}
                            {--currency=USD : Currency code}
                            {--timezone=America/New_York : Timezone}';

    protected $description = 'Create a Google Ads sub-account under the MCC for a customer';

    public function handle()
    {
        $customerId = $this->argument('customer_id');
        $customer = Customer::find($customerId);

        if (!$customer) {
            $this->error("Customer with ID {$customerId} not found");
            return 1;
        }

        // Check if customer already has a Google Ads account
        if ($customer->google_ads_customer_id) {
            $this->warn("Customer already has a Google Ads account: {$customer->google_ads_customer_id}");
            if (!$this->confirm('Do you want to create a new sub-account anyway?')) {
                return 0;
            }
        }

        $mccCustomerId = config('googleads.mcc_customer_id');
        if (!$mccCustomerId) {
            $this->error('MCC Customer ID not configured in config/googleads.php');
            return 1;
        }

        $accountName = $this->option('name') ?: ($customer->name . ' - Google Ads');
        $currencyCode = $this->option('currency');
        $timeZone = $this->option('timezone');

        $this->info("Creating Google Ads sub-account under MCC {$mccCustomerId}...");
        $this->info("Account Name: {$accountName}");
        $this->info("Currency: {$currencyCode}");
        $this->info("Timezone: {$timeZone}");

        try {
            $createService = new CreateAndLinkManagedAccount(
                $customer,
                app(CreateManagedAccount::class),
                app(CreateCustomerClientLink::class)
            );

            $result = $createService(
                $mccCustomerId,
                $accountName,
                $currencyCode,
                $timeZone
            );

            if (!$result) {
                $this->error('Failed to create sub-account. Check logs for details.');
                return 1;
            }

            // Update customer record with new Google Ads customer ID
            $customer->google_ads_customer_id = $result['customer_id'];
            $customer->save();

            $this->info("✅ Successfully created Google Ads sub-account!");
            $this->info("Customer ID: {$result['customer_id']}");
            $this->info("Resource Name: {$result['resource_name']}");
            $this->info("Updated customer record #{$customer->id}");

            return 0;
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            Log::error("Failed to create Google Ads sub-account", [
                'customer_id' => $customerId,
                'exception' => $e->getMessage(),
            ]);
            return 1;
        }
    }
}
