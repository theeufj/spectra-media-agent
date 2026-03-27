<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\GoogleAds\MCCAccountManager;
use App\Services\GoogleAds\CreateManagedAccount;

class TestMCCAutoCreation extends Command
{
    protected $signature = 'googleads:test-mcc-auto-create {customer_id : The Laravel customer ID} {account_id : The Google Ads account ID to test}';

    protected $description = 'Test MCC account detection and automatic Standard account creation';

    public function handle()
    {
        $customerId = $this->argument('customer_id');
        $accountId = $this->argument('account_id');

        $customer = Customer::find($customerId);
        if (!$customer) {
            $this->error("Customer {$customerId} not found");
            return 1;
        }

        $this->info("Testing MCC Account Detection and Auto-Creation");
        $this->line("=".str_repeat("=", 50));
        $this->newLine();

        try {
            // Create the MCCAccountManager
            $mccManager = new MCCAccountManager($customer);

            $this->info("Step 1: Getting account info for {$accountId}...");
            $accountInfo = $mccManager->getAccountInfo($accountId);

            if (!$accountInfo) {
                $this->error("Failed to fetch account info. Check logs for details.");
                return 1;
            }

            $this->line("✅ Account Info Retrieved:");
            $this->table(
                ['Property', 'Value'],
                [
                    ['ID', $accountInfo['account_id']],
                    ['Name', $accountInfo['descriptive_name']],
                    ['Is Manager (MCC)', $accountInfo['is_manager'] ? 'Yes' : 'No'],
                    ['Currency', $accountInfo['currency_code']],
                    ['Time Zone', $accountInfo['time_zone']],
                ]
            );

            $this->newLine();

            if (!$accountInfo['is_manager']) {
                $this->warn("Account is NOT an MCC. Storing directly.");
                $result = $mccManager->handleAccountSelection($accountId);
                if ($result) {
                    $this->info("✅ Standard account stored:");
                    $this->line("   Account ID: " . $result['account_id']);
                }
                return 0;
            }

            $this->info("Step 2: Account is an MCC. Creating Standard account under it...");

            $result = $mccManager->handleAccountSelection($accountId);

            if (!$result) {
                $this->error("Failed to create Standard account. Check logs for details.");
                return 1;
            }

            $this->newLine();
            $this->line("✅ Standard Account Created Successfully!");
            $this->table(
                ['Property', 'Value'],
                [
                    ['Standard Account ID', $result['account_id']],
                    ['MCC Account ID', $result['mcc_account_id']],
                    ['Is New Account', $result['is_new_account'] ? 'Yes' : 'No'],
                ]
            );

            $this->newLine();
            $this->info("Customer record updated:");
            $customer->refresh();
            $this->table(
                ['Field', 'Value'],
                [
                    ['google_ads_customer_id', $customer->google_ads_customer_id],
                    ['google_ads_manager_customer_id', $customer->google_ads_manager_customer_id ?? 'none'],
                   ['google_ads_customer_is_manager', $customer->google_ads_customer_is_manager ? 'true' : 'false'],
                ]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("\nStack trace:");
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
