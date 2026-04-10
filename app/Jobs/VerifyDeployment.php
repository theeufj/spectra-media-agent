<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\FacebookAds\CampaignService;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs after deployment to verify that platform objects actually exist,
 * then transitions deployment_status from 'deployed' → 'verified'.
 *
 * Dispatched by DeployCampaign after a successful deployment.
 * Delayed by 60 seconds to allow platform propagation.
 */
class VerifyDeployment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 120, 300]; // retry after 1m, 2m, 5m

    public function __construct(
        protected Campaign $campaign
    ) {}

    public function handle(): void
    {
        $strategies = $this->campaign->strategies()
            ->where('deployment_status', 'deployed')
            ->get();

        if ($strategies->isEmpty()) {
            Log::info("VerifyDeployment: No deployed strategies to verify for campaign {$this->campaign->id}");
            return;
        }

        $customer = $this->campaign->customer;

        foreach ($strategies as $strategy) {
            try {
                $verified = $this->verifyStrategy($strategy, $customer);

                $strategy->update([
                    'deployment_status' => $verified ? 'verified' : 'deploy_unverified',
                ]);

                Log::info("VerifyDeployment: Strategy {$strategy->id} ({$strategy->platform}): " . ($verified ? 'verified' : 'unverified'));
            } catch (\Exception $e) {
                Log::error("VerifyDeployment: Failed to verify strategy {$strategy->id}: " . $e->getMessage());
            }
        }
    }

    private function verifyStrategy(Strategy $strategy, $customer): bool
    {
        $platform = strtolower($strategy->platform ?? '');
        $platformIds = $strategy->execution_result['platform_ids'] ?? [];

        if (str_contains($platform, 'google')) {
            return $this->verifyGoogleAds($strategy, $customer, $platformIds);
        }

        if (str_contains($platform, 'facebook')) {
            return $this->verifyFacebookAds($strategy, $customer, $platformIds);
        }

        return false;
    }

    private function verifyGoogleAds(Strategy $strategy, $customer, array $platformIds): bool
    {
        $googleCampaignId = $platformIds['campaign_id']
            ?? $strategy->campaign->google_ads_campaign_id
            ?? null;

        if (!$googleCampaignId || !$customer->google_ads_customer_id) {
            return false;
        }

        $customerId = str_replace('-', '', $customer->google_ads_customer_id);

        // google_ads_campaign_id stores the full resource name (customers/X/campaigns/Y)
        $resourceName = $googleCampaignId;
        if (!str_starts_with($resourceName, 'customers/')) {
            $resourceName = "customers/{$customerId}/campaigns/{$googleCampaignId}";
        }

        $service = new GetCampaignStatus($customer);
        $status = $service($customerId, $resourceName);

        return $status !== null;
    }

    private function verifyFacebookAds(Strategy $strategy, $customer, array $platformIds): bool
    {
        $fbCampaignId = $platformIds['campaign_id']
            ?? $strategy->facebook_campaign_id
            ?? null;

        if (!$fbCampaignId || !$customer->facebook_ads_account_id) {
            return false;
        }

        $service = new CampaignService($customer);
        $campaigns = $service->listCampaigns($customer->facebook_ads_account_id);

        if ($campaigns === null) {
            return false;
        }

        // Check if our campaign ID exists in the account
        foreach ($campaigns as $campaign) {
            if (($campaign['id'] ?? '') === (string) $fbCampaignId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('VerifyDeployment failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
