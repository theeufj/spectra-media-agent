<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

class MicrosoftAdsLinkAccount extends Command
{
    protected $signature = 'microsoftads:link-account
                            {--customer-id= : Laravel customer ID}
                            {--account-id= : Microsoft Ads account ID}
                            {--ms-customer-id= : Microsoft Ads customer (parent) ID}';

    protected $description = 'Link a Microsoft Ads account to a customer record';

    public function handle(): int
    {
        $customerId   = $this->option('customer-id');
        $accountId    = $this->option('account-id');
        $msCustomerId = $this->option('ms-customer-id');

        if (!$customerId || !$accountId || !$msCustomerId) {
            $this->error('All three options are required: --customer-id, --account-id, --ms-customer-id');
            return 1;
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            $this->error("Customer {$customerId} not found.");
            return 1;
        }

        $customer->update([
            'microsoft_ads_account_id'  => $accountId,
            'microsoft_ads_customer_id' => $msCustomerId,
        ]);

        $this->info("Linked! Customer '{$customer->name}' now has:");
        $this->line("  microsoft_ads_account_id:  {$accountId}");
        $this->line("  microsoft_ads_customer_id: {$msCustomerId}");
        $this->line('');
        $this->info("Microsoft Ads is now enabled for this customer. It will appear as an available platform when deploying campaigns.");

        return 0;
    }
}
