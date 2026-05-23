<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\ConversionSetupService;
use App\Services\GTM\GTMContainerService;
use Illuminate\Console\Command;

/**
 * Force-provisions a Spectra-managed GTM container for a customer and wires up
 * all conversion tags (Google Ads, Meta Pixel, Microsoft UET), then publishes.
 *
 * Use this when a customer already has a conversion_action_id but GTM was never
 * provisioned (gtm_account_id is null).
 *
 * Usage:
 *   php artisan customer:setup-gtm --customer=8
 */
class SetupCustomerGtm extends Command
{
    protected $signature = 'customer:setup-gtm
                            {--customer= : Customer ID to set up GTM for}
                            {--force : Re-provision even if GTM is already set up}';

    protected $description = 'Provision Spectra-managed GTM container and wire conversion tags for a customer';

    public function handle(ConversionSetupService $service): int
    {
        $customerId = $this->option('customer');
        if (!$customerId) {
            $this->error('--customer is required');
            return self::FAILURE;
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            $this->error("Customer {$customerId} not found");
            return self::FAILURE;
        }

        $this->info("Customer: [{$customer->id}] {$customer->name}");
        $this->info("Google Ads: " . ($customer->google_ads_customer_id ?? 'none'));
        $this->info("Facebook Pixel: " . ($customer->facebook_pixel_id ?? 'none'));
        $this->info("Current GTM container: " . ($customer->gtm_container_id ?? 'none'));
        $this->info("Current GTM account: " . ($customer->gtm_account_id ?? 'none (not Spectra-managed)'));
        $this->newLine();

        $spectraAccountId = config('services.gtm.platform_account_id');
        $hasSpectraContainer = $spectraAccountId
            && $customer->gtm_account_id === $spectraAccountId
            && $customer->gtm_container_id
            && $customer->gtm_workspace_id;

        if ($hasSpectraContainer && !$this->option('force')) {
            $this->warn("Customer already has a Spectra-managed GTM container ({$customer->gtm_container_id}). Use --force to re-provision.");
            return self::SUCCESS;
        }

        $this->info("Running full conversion setup (GTM provision + tag wiring + publish)...");

        $result = $service->setup($customer);

        $this->newLine();

        if (!$result['success'] && !empty($result['errors']) && !isset($result['resource_name'])) {
            $this->error("Setup failed:");
            foreach ($result['errors'] as $err) {
                $this->error("  - {$err}");
            }
            return self::FAILURE;
        }

        $customer->refresh();

        $this->info("Conversion action: " . ($result['resource_name'] ?? $customer->conversion_action_id));
        $this->info("Conversion label:  " . ($result['conversion_id'] ?? $customer->conversion_action_label));
        $this->info("Facebook pixel:    " . ($result['facebook_pixel_id'] ?? $customer->facebook_pixel_id ?? 'none'));
        $this->info("GTM container:     " . ($customer->gtm_container_id ?? 'not set'));
        $this->info("GTM account:       " . ($customer->gtm_account_id ?? 'not set'));
        $this->info("GTM workspace:     " . ($customer->gtm_workspace_id ?? 'not set'));
        $this->newLine();

        if (!empty($result['errors'])) {
            $this->warn("Completed with warnings:");
            foreach ($result['errors'] as $err) {
                $this->warn("  - {$err}");
            }
            $this->newLine();
        }

        if (!empty($result['snippet'])) {
            $this->info("=== GTM INSTALL SNIPPET (add inside <head> on sitetospend.com) ===");
            $this->line($result['snippet']);
            $this->newLine();
        } else {
            $this->warn("No snippet returned — customer may need to install the GTM container manually.");
            $this->info("GTM Container ID to install: " . ($customer->gtm_container_id ?? 'check GTM dashboard'));
        }

        $this->info("Done. Conversion tracking is now wired up.");
        return self::SUCCESS;
    }
}
