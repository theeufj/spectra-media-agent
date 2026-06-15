<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\PMaxAssetOptimizationAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Check PMax asset performance and auto-optimize low performers.
 *
 * Usage:
 *   php artisan pmax:check-assets                    # All customers with active PMax campaigns
 *   php artisan pmax:check-assets --customer=42      # Specific customer, all active campaigns
 *   php artisan pmax:check-assets --campaign=123     # Specific campaign
 */
class CheckPMaxAssets extends Command
{
    protected $signature = 'pmax:check-assets
                            {--customer= : Customer ID to check (all active PMax campaigns)}
                            {--campaign= : Specific Campaign ID to check}';

    protected $description = 'Check PMax asset group performance and replace low-performing assets';

    public function handle(): int
    {
        $campaignId = $this->option('campaign');
        $customerId = $this->option('customer');

        if ($campaignId) {
            return $this->runForCampaign((int) $campaignId);
        }

        if ($customerId) {
            return $this->runForCustomer((int) $customerId);
        }

        return $this->runForAllCustomers();
    }

    /**
     * Run the agent for a single campaign.
     */
    private function runForCampaign(int $campaignId): int
    {
        $campaign = Campaign::with('customer')->find($campaignId);

        if (!$campaign) {
            $this->error("Campaign {$campaignId} not found.");
            return self::FAILURE;
        }

        $customer = $campaign->customer;
        if (!$customer) {
            $this->error("No customer found for campaign {$campaignId}.");
            return self::FAILURE;
        }

        if (!$campaign->google_ads_campaign_id) {
            $this->warn("Campaign {$campaignId} has no google_ads_campaign_id — skipping.");
            return self::SUCCESS;
        }

        $this->info("Checking PMax assets for campaign: {$campaign->name} (#{$campaignId})");

        $result = $this->runAgent($customer, $campaign);
        $this->outputResult($result, $campaign);

        return self::SUCCESS;
    }

    /**
     * Run the agent for all active campaigns of a specific customer.
     */
    private function runForCustomer(int $customerId): int
    {
        $customer = Customer::find($customerId);

        if (!$customer) {
            $this->error("Customer {$customerId} not found.");
            return self::FAILURE;
        }

        $this->info("Checking PMax assets for customer: {$customer->name} (#{$customerId})");

        $campaigns = Campaign::where('customer_id', $customerId)
            ->whereNotNull('google_ads_campaign_id')
            ->where('status', 'active')
            ->get();

        if ($campaigns->isEmpty()) {
            $this->warn("No active PMax campaigns found for customer {$customerId}.");
            return self::SUCCESS;
        }

        foreach ($campaigns as $campaign) {
            $this->line("  → Campaign: {$campaign->name} (#{$campaign->id})");
            $result = $this->runAgent($customer, $campaign);
            $this->outputResult($result, $campaign);
        }

        return self::SUCCESS;
    }

    /**
     * Run the agent for all customers with active PMax campaigns.
     */
    private function runForAllCustomers(): int
    {
        $this->info("Checking PMax assets for all customers with active campaigns...");

        $customers = Customer::whereHas('campaigns', function ($q) {
            $q->whereNotNull('google_ads_campaign_id')
              ->where('status', 'active');
        })->get();

        if ($customers->isEmpty()) {
            $this->warn("No customers with active PMax campaigns found.");
            return self::SUCCESS;
        }

        $this->info("Found {$customers->count()} customer(s) to process.");

        foreach ($customers as $customer) {
            $this->info("Customer: {$customer->name} (#{$customer->id})");

            $campaigns = Campaign::where('customer_id', $customer->id)
                ->whereNotNull('google_ads_campaign_id')
                ->where('status', 'active')
                ->get();

            foreach ($campaigns as $campaign) {
                $this->line("  → Campaign: {$campaign->name} (#{$campaign->id})");
                $result = $this->runAgent($customer, $campaign);
                $this->outputResult($result, $campaign);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Instantiate and run the PMaxAssetOptimizationAgent.
     */
    private function runAgent(Customer $customer, Campaign $campaign): array
    {
        try {
            $agent = new PMaxAssetOptimizationAgent($customer);
            return $agent->run($campaign);
        } catch (\Exception $e) {
            Log::error("CheckPMaxAssets: Agent failed for campaign {$campaign->id}: " . $e->getMessage());
            return [
                'low_detected'  => 0,
                'text_replaced' => 0,
                'image_flagged' => 0,
                'errors'        => [$e->getMessage()],
            ];
        }
    }

    /**
     * Output the result of a single campaign run.
     */
    private function outputResult(array $result, Campaign $campaign): void
    {
        $this->info(sprintf(
            '    Low detected: %d | Text replaced: %d | Images flagged: %d',
            $result['low_detected'],
            $result['text_replaced'],
            $result['image_flagged']
        ));

        foreach ($result['errors'] as $error) {
            $this->warn("    Error: {$error}");
        }
    }
}
