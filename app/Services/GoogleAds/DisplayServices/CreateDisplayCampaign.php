<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V15\Resources\Campaign;
use Google\Ads\GoogleAds\V15\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V15\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V15\Enums\BudgetTypeEnum\BudgetType;
use Google\Ads\GoogleAds\V15\Services\CampaignBudgetService;
use Google\Ads\GoogleAds\V15\Services\CampaignService;
use Google\Ads\GoogleAds\V15\Services\CampaignOperation;
use Google\Ads\GoogleAds\V15\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V15\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V15\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V15\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V15\Common\TargetCpa;
use Google\Ads\GoogleAds\V15\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateDisplayCampaign extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a new Google Ads Display campaign under a specified customer account.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param array $campaignData Campaign details including businessName, budget, startDate, endDate, etc.
     * @return string|null The resource name of the created campaign, or null on failure.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        // Check if a campaign with the same name already exists.
        $campaignName = $campaignData['businessName'] . ' Display Campaign';
        $existingCampaign = $this->getCampaignByName($customerId, $campaignName);
        if ($existingCampaign) {
            $this->logInfo("Campaign '{$campaignName}' already exists. Skipping creation.");
            return $existingCampaign->getResourceName();
        }

        // 1. Create Campaign Budget
        $campaignBudgetResourceName = $this->createCampaignBudget($customerId, $campaignData['budget']);
        if (is_null($campaignBudgetResourceName)) {
            $this->logError("Failed to create campaign budget for customer $customerId.");
            return null;
        }

        // 2. Create Campaign
        $campaign = new Campaign([
            'name' => $campaignName,
            'advertising_channel_type' => AdvertisingChannelType::DISPLAY,
            'campaign_budget' => $campaignBudgetResourceName,
            'status' => CampaignStatus::PAUSED, // Start paused to allow further configuration
            'start_date' => $campaignData['startDate'],
            'end_date' => $campaignData['endDate'],
            'maximize_conversions' => new MaximizeConversions(), // Default for display campaigns
        ]);

        // Apply bidding strategy (example: TargetCpa)
        if (isset($campaignData['biddingStrategyType']) && $campaignData['biddingStrategyType'] === 'TARGET_CPA') {
            $campaign->setTargetCpa(new TargetCpa([
                'target_cpa_micros' => $campaignData['targetCpaMicros'] ?? null,
            ]));
        }

        $campaignOperation = new CampaignOperation();
        $campaignOperation->create = $campaign;

        try {
            $campaignServiceClient = $this->client->getCampaignServiceClient();
            $response = $campaignServiceClient->mutateCampaigns($customerId, [$campaignOperation]);
            $newCampaignResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Display campaign: " . $newCampaignResourceName);
            return $newCampaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Display campaign for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }

    /**
     * Creates a campaign budget for a new campaign.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param float $budgetAmount The daily budget amount.
     * @return string|null The resource name of the created campaign budget, or null on failure.
     */
    private function createCampaignBudget(string $customerId, float $budgetAmount): ?string
    {
        $campaignBudget = new CampaignBudget([
            'name' => 'Daily Budget - ' . uniqid(),
            'amount_micros' => (int) ($budgetAmount * 1_000_000), // Convert to micros
            'delivery_method' => BudgetType::STANDARD,
            'explicitly_shared' => false
        ]);

        $campaignBudgetOperation = new CampaignBudgetOperation();
        $campaignBudgetOperation->create = $campaignBudget;

        try {
            $campaignBudgetServiceClient = $this->client->getCampaignBudgetServiceClient();
            $response = $campaignBudgetServiceClient->mutateCampaignBudgets($customerId, [$campaignBudgetOperation]);
            $newBudgetResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created campaign budget: " . $newBudgetResourceName);
            return $newBudgetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating campaign budget for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }

    private function getCampaignByName(string $customerId, string $campaignName): ?Campaign
    {
        $query = "SELECT campaign.resource_name, campaign.name FROM campaign WHERE campaign.name = '{$campaignName}'";
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $response = $googleAdsServiceClient->search($customerId, $query);

            foreach ($response->getIterator() as $googleAdsRow) {
                return $googleAdsRow->getCampaign();
            }
        } catch (GoogleAdsException $e) {
            $this->logError("Error fetching campaign by name for customer $customerId: " . $e->getMessage(), $e);
        }

        return null;
    }
}
