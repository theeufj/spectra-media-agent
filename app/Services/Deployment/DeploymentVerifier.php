<?php

namespace App\Services\Deployment;

use App\Models\Customer;
use App\Models\Strategy;
use App\Services\FacebookAds\CampaignService;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;

/**
 * Answers one question: do the platform objects this strategy claims to have
 * created actually exist?
 *
 * Extracted from VerifyDeployment so ReconcileStuckDeployments can ask the same
 * question of strategies that never reached a terminal state at all.
 */
class DeploymentVerifier
{
    public function verify(Strategy $strategy, ?Customer $customer): bool
    {
        if (! $customer) {
            return false;
        }

        $platform = strtolower($strategy->platform ?? '');
        $platformIds = $strategy->execution_result['platform_ids'] ?? [];

        if (str_contains($platform, 'google')) {
            return $this->verifyGoogleAds($strategy, $customer, $platformIds);
        }

        if (str_contains($platform, 'facebook')) {
            return $this->verifyFacebookAds($strategy, $customer, $platformIds);
        }

        // Microsoft and LinkedIn have no verification path yet. Returning false
        // means "not proven", which callers must not treat as "proven absent".
        return false;
    }

    private function verifyGoogleAds(Strategy $strategy, Customer $customer, array $platformIds): bool
    {
        $googleCampaignId = $platformIds['campaign_id']
            ?? $strategy->campaign->google_ads_campaign_id
            ?? null;

        if (! $googleCampaignId || ! $customer->google_ads_customer_id) {
            return false;
        }

        $customerId = $customer->cleanGoogleCustomerId();

        // google_ads_campaign_id stores the full resource name (customers/X/campaigns/Y)
        $resourceName = $googleCampaignId;
        if (! str_starts_with($resourceName, 'customers/')) {
            $resourceName = "customers/{$customerId}/campaigns/{$googleCampaignId}";
        }

        return (new GetCampaignStatus($customer))($customerId, $resourceName) !== null;
    }

    private function verifyFacebookAds(Strategy $strategy, Customer $customer, array $platformIds): bool
    {
        $fbCampaignId = $platformIds['campaign_id']
            ?? $strategy->facebook_campaign_id
            ?? null;

        if (! $fbCampaignId || ! $customer->facebook_ads_account_id) {
            return false;
        }

        $campaigns = (new CampaignService($customer))->listCampaigns($customer->facebook_ads_account_id);

        if ($campaigns === null) {
            return false;
        }

        foreach ($campaigns as $campaign) {
            if (($campaign['id'] ?? '') === (string) $fbCampaignId) {
                return true;
            }
        }

        return false;
    }
}
