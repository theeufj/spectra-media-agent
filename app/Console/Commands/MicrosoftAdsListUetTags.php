<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\MicrosoftAds\ConversionTrackingService;
use Illuminate\Console\Command;

class MicrosoftAdsListUetTags extends Command
{
    protected $signature = 'microsoftads:list-uet-tags {--customer-id= : Laravel customer ID (default: first with MS account)}';

    protected $description = 'List UET tags for a Microsoft Ads account and print the tag ID for .env';

    public function handle(): int
    {
        $customerId = $this->option('customer-id');

        $customer = $customerId
            ? Customer::find($customerId)
            : Customer::whereNotNull('microsoft_ads_account_id')->first();

        if (!$customer) {
            $this->error('No customer found. Pass --customer-id=N or link an account first.');
            return 1;
        }

        $this->info("Fetching UET tags for: {$customer->name} (account {$customer->microsoft_ads_account_id})");

        $service = new ConversionTrackingService($customer);
        $tags = $service->getUetTags();

        if (empty($tags)) {
            $this->warn('No UET tags found. Run a Microsoft Ads campaign deployment first to auto-create one.');
            return 0;
        }

        $this->line('');
        $this->line(str_pad('Tag ID', 15) . str_pad('Name', 40) . 'Status');
        $this->line(str_repeat('-', 70));

        foreach ($tags as $tag) {
            $id     = $tag['Id'] ?? 'N/A';
            $name   = $tag['Name'] ?? 'N/A';
            $status = $tag['TrackingStatus'] ?? 'Unverified';
            $this->line(str_pad($id, 15) . str_pad($name, 40) . $status);
        }

        $this->line('');

        $firstId = $tags[0]['Id'] ?? null;
        if ($firstId) {
            $this->info("Add to your .env / Forge environment variables:");
            $this->line("  MICROSOFT_ADS_UET_TAG_ID={$firstId}");
            $this->line('');
            $this->info("Then redeploy — the UET snippet will appear on every page of sitetospend.com automatically.");
        }

        return 0;
    }
}
