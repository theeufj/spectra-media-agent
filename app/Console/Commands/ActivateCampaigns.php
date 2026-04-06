<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\CampaignStatusHelper;
use App\Services\FacebookAds\CampaignService;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Illuminate\Console\Command;

class ActivateCampaigns extends Command
{
    protected $signature = 'campaigns:activate
        {campaign? : Campaign ID to activate (all deployed if omitted)}
        {--dry-run : Show what would be activated without making changes}';

    protected $description = 'Transition deployed campaigns from PAUSED to ACTIVE/ENABLED on their platforms';

    public function handle(): int
    {
        if (CampaignStatusHelper::isTestingMode()) {
            $this->warn('Campaign testing mode is ON — campaigns are deployed as PAUSED.');
            $this->warn('Disable testing mode in Settings before activating.');
            return self::FAILURE;
        }

        $campaignId = $this->argument('campaign');

        $query = Strategy::with('campaign.customer')
            ->whereIn('deployment_status', ['deployed', 'verified']);

        if ($campaignId) {
            $query->where('campaign_id', $campaignId);
        }

        $strategies = $query->get();

        if ($strategies->isEmpty()) {
            $this->info('No deployed strategies found to activate.');
            return self::SUCCESS;
        }

        $this->info("Found {$strategies->count()} strategy(ies) to activate.");

        $successCount = 0;
        $failCount = 0;

        foreach ($strategies as $strategy) {
            $campaign = $strategy->campaign;
            $customer = $campaign?->customer;

            if (!$customer) {
                $this->warn("Strategy {$strategy->id}: No customer found, skipping.");
                continue;
            }

            $platform = strtolower($strategy->platform ?? '');
            $label = "Strategy {$strategy->id} ({$strategy->platform}) - Campaign \"{$campaign->name}\"";

            if ($this->option('dry-run')) {
                $this->line("  [DRY RUN] Would activate: {$label}");
                continue;
            }

            $this->line("  Activating: {$label}");

            $activated = false;

            if (str_contains($platform, 'google')) {
                $activated = $this->activateGoogleAds($strategy, $campaign, $customer);
            } elseif (str_contains($platform, 'facebook')) {
                $activated = $this->activateFacebook($strategy, $campaign, $customer);
            } else {
                $this->warn("    Unknown platform '{$strategy->platform}', skipping.");
                continue;
            }

            if ($activated) {
                $strategy->update(['deployment_status' => 'active']);
                $this->info("    Activated successfully.");
                $successCount++;
            } else {
                $this->error("    Activation failed.");
                $failCount++;
            }
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run complete. {$strategies->count()} strategies would be activated.");
        } else {
            $this->info("Done. {$successCount} activated, {$failCount} failed.");
        }

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function activateGoogleAds(Strategy $strategy, Campaign $campaign, $customer): bool
    {
        $googleCampaignId = $strategy->execution_result['platform_ids']['campaign_id']
            ?? $campaign->google_ads_campaign_id
            ?? null;

        if (!$googleCampaignId || !$customer->google_ads_customer_id) {
            $this->warn("    Missing Google Ads campaign ID or customer ID.");
            return false;
        }

        $customerId = str_replace('-', '', $customer->google_ads_customer_id);
        $resourceName = "customers/{$customerId}/campaigns/{$googleCampaignId}";

        $service = new UpdateCampaignStatus($customer);
        $result = $service->enable($customerId, $resourceName);

        return $result['success'] ?? false;
    }

    private function activateFacebook(Strategy $strategy, Campaign $campaign, $customer): bool
    {
        $fbCampaignId = $strategy->execution_result['platform_ids']['campaign_id']
            ?? null;

        if (!$fbCampaignId || !$customer->facebook_ads_account_id) {
            $this->warn("    Missing Facebook campaign ID or ad account ID.");
            return false;
        }

        $service = new CampaignService($customer);

        return $service->updateCampaign($fbCampaignId, ['status' => 'ACTIVE']);
    }
}
