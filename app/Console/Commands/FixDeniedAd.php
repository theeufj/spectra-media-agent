<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\GoogleAds\CommonServices\UpdateResponsiveSearchAd;
use Illuminate\Console\Command;

/**
 * One-shot command to replace policy-violating headlines on a specific RSA.
 *
 * Targets the Sitetospend launch campaign ad that received APPROVED_LIMITED
 * due to "96% Cheaper AI Ad Management" (unsubstantiated percentage claim).
 */
class FixDeniedAd extends Command
{
    protected $signature = 'ads:fix-denied-ad
                            {--customer-id=3598653839 : Google Ads customer ID}
                            {--ad-resource= : Override the adGroupAd resource name}
                            {--dry-run : Show what would change without applying}';

    protected $description = 'Replace policy-violating headlines on the Sitetospend launch ad';

    // The ad that came back APPROVED_LIMITED / IN_REVIEW
    private const DEFAULT_AD_RESOURCE = 'customers/3598653839/adGroupAds/195151790879~806770303898';

    private const REPLACEMENT_HEADLINES = [
        'Stop Paying Agency Retainers',
        'AI Ads at Agency Quality',
        '24/7 Autonomous AI Agents',
        'Ad Management From $99/mo',
        'Self-Healing Ad Campaigns',
        '6 AI Agents Managing Ads',
        'Agency Quality, Utility Cost',
        'Deploy Your Ads in 1 Click',
        'No Retainers. No Setup Fees.',
        '6 AI Agents Optimizing 24/7',
        'AI-Managed Ads, Human Results',
    ];

    public function handle(): int
    {
        $customerId  = $this->option('customer-id');
        $adResource  = $this->option('ad-resource') ?: self::DEFAULT_AD_RESOURCE;
        $dryRun      = $this->option('dry-run');

        $customer = Customer::where('google_ads_customer_id', $customerId)->first();
        if (!$customer) {
            $this->error("No customer found with google_ads_customer_id = {$customerId}");
            return 1;
        }

        $this->info("Customer: {$customer->name} (ID {$customer->id})");
        $this->info("Ad resource: {$adResource}");

        // Fetch current ad state to get existing descriptions
        $getStatus = new GetAdStatus($customer);
        $ads = $getStatus($customerId);

        $target = collect($ads)->first(fn($a) => $a['resource_name'] === $adResource);
        if (!$target) {
            $this->error("Ad not found in account. Check the resource name.");
            $this->line("Available ads:");
            foreach ($ads as $ad) {
                $this->line("  {$ad['resource_name']}");
            }
            return 1;
        }

        $existingHeadlines    = $target['headlines'];
        $existingDescriptions = $target['descriptions'];

        $this->line("\n<fg=yellow>Current headlines:</>");
        foreach ($existingHeadlines as $h) {
            $flag = $this->isFlagged($h) ? ' <fg=red>[FLAGGED]</>' : '';
            $this->line("  - {$h}{$flag}");
        }

        $this->line("\n<fg=yellow>Current descriptions:</>");
        foreach ($existingDescriptions as $d) {
            $this->line("  - {$d}");
        }

        $this->line("\n<fg=green>Replacement headlines:</>");
        foreach (self::REPLACEMENT_HEADLINES as $h) {
            $this->line("  - {$h}");
        }

        if ($dryRun) {
            $this->warn("\n[DRY RUN] No changes applied.");
            return 0;
        }

        if (!$this->confirm("\nApply replacement headlines?", true)) {
            $this->info('Aborted.');
            return 0;
        }

        $updater = new UpdateResponsiveSearchAd($customer);
        $result  = $updater->replace(
            $customerId,
            $adResource,
            self::REPLACEMENT_HEADLINES,
            $existingDescriptions
        );

        if ($result) {
            $this->info("\nAd updated successfully. Google will re-review the ad shortly.");
            return 0;
        }

        $this->error("\nUpdate failed — check Laravel logs for details.");
        return 1;
    }

    private function isFlagged(string $headline): bool
    {
        $flagged = [
            '96% cheaper',
            'save thousands',
        ];
        $lower = strtolower($headline);
        foreach ($flagged as $f) {
            if (str_contains($lower, $f)) {
                return true;
            }
        }
        return false;
    }
}
