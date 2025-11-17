<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class FacebookAdsOrchestrationService
{
    protected AdAccountService $adAccountService;
    protected CampaignService $campaignService;
    protected AdSetService $adSetService;
    protected AdService $adService;
    protected CreativeService $creativeService;
    protected Customer $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->adAccountService = new AdAccountService($customer);
        $this->campaignService = new CampaignService($customer);
        $this->adSetService = new AdSetService($customer);
        $this->adService = new AdService($customer);
        $this->creativeService = new CreativeService($customer);
    }

    /**
     * Get all ad accounts.
     *
     * @return ?array
     */
    public function getAdAccounts(): ?array
    {
        return $this->adAccountService->listAdAccounts();
    }

    /**
     * Create a complete campaign structure with ad set and ad.
     *
     * @param string $accountId Ad account ID
     * @param string $campaignName Campaign name
     * @param string $campaignObjective Campaign objective
     * @param int $campaignBudget Daily budget in cents
     * @param string $adSetName Ad set name
     * @param int $adSetBudget Ad set daily budget in cents
     * @param array $targeting Targeting parameters
     * @param string $adName Ad name
     * @param string $creativeId Creative ID
     * @return ?array
     */
    public function createCompleteCampaign(
        string $accountId,
        string $campaignName,
        string $campaignObjective,
        int $campaignBudget,
        string $adSetName,
        int $adSetBudget,
        array $targeting,
        string $adName,
        string $creativeId
    ): ?array {
        try {
            // Step 1: Create campaign
            $campaign = $this->campaignService->createCampaign(
                $accountId,
                $campaignName,
                $campaignObjective,
                $campaignBudget
            );

            if (!$campaign || !isset($campaign['id'])) {
                Log::error("Failed to create campaign in complete campaign flow", [
                    'account_id' => $accountId,
                    'campaign_name' => $campaignName,
                ]);
                return null;
            }

            // Step 2: Create ad set
            $adSet = $this->adSetService->createAdSet(
                $campaign['id'],
                $adSetName,
                $adSetBudget,
                $targeting,
                'LINK_CLICKS',
                $campaignObjective
            );

            if (!$adSet || !isset($adSet['id'])) {
                Log::error("Failed to create ad set in complete campaign flow", [
                    'campaign_id' => $campaign['id'],
                    'adset_name' => $adSetName,
                ]);
                return null;
            }

            // Step 3: Create ad
            $ad = $this->adService->createAd(
                $adSet['id'],
                $adName,
                $creativeId
            );

            if (!$ad || !isset($ad['id'])) {
                Log::error("Failed to create ad in complete campaign flow", [
                    'adset_id' => $adSet['id'],
                    'ad_name' => $adName,
                ]);
                return null;
            }

            Log::info("Successfully created complete campaign structure", [
                'customer_id' => $this->customer->id,
                'campaign_id' => $campaign['id'],
                'adset_id' => $adSet['id'],
                'ad_id' => $ad['id'],
            ]);

            return [
                'campaign' => $campaign,
                'ad_set' => $adSet,
                'ad' => $ad,
            ];
        } catch (\Exception $e) {
            Log::error("Error creating complete campaign: " . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Get performance data for a campaign.
     *
     * @param string $campaignId Campaign ID
     * @param string $dateStart Start date (YYYY-MM-DD)
     * @param string $dateEnd End date (YYYY-MM-DD)
     * @return ?array
     */
    public function getCampaignPerformance(string $campaignId, string $dateStart, string $dateEnd): ?array
    {
        try {
            $campaignInsights = $this->campaignService->getCampaignInsights($campaignId, $dateStart, $dateEnd);

            return [
                'campaign_insights' => $campaignInsights,
            ];
        } catch (\Exception $e) {
            Log::error("Error getting campaign performance: " . $e->getMessage(), [
                'exception' => $e,
                'campaign_id' => $campaignId,
            ]);
            return null;
        }
    }

    /**
     * Pause a complete campaign (campaign, all ad sets, and all ads).
     *
     * @param string $campaignId Campaign ID
     * @return bool
     */
    public function pauseCampaign(string $campaignId): bool
    {
        try {
            $adSets = $this->adSetService->listAdSets($campaignId);

            if ($adSets) {
                foreach ($adSets as $adSet) {
                    $this->adSetService->updateAdSet($adSet['id'], ['status' => 'PAUSED']);
                }
            }

            Log::info("Paused campaign {$campaignId} and its ad sets", [
                'customer_id' => $this->customer->id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error pausing campaign: " . $e->getMessage(), [
                'exception' => $e,
                'campaign_id' => $campaignId,
            ]);
            return false;
        }
    }

    /**
     * Resume a complete campaign.
     *
     * @param string $campaignId Campaign ID
     * @return bool
     */
    public function resumeCampaign(string $campaignId): bool
    {
        try {
            $adSets = $this->adSetService->listAdSets($campaignId);

            if ($adSets) {
                foreach ($adSets as $adSet) {
                    $this->adSetService->updateAdSet($adSet['id'], ['status' => 'ACTIVE']);
                }
            }

            Log::info("Resumed campaign {$campaignId} and its ad sets", [
                'customer_id' => $this->customer->id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error resuming campaign: " . $e->getMessage(), [
                'exception' => $e,
                'campaign_id' => $campaignId,
            ]);
            return false;
        }
    }
}
