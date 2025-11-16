<?php

namespace App\Services\GoogleAds\VideoServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V15\Resources\Campaign;
use Google\Ads\GoogleAds\V15\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V15\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V15\Enums\AdvertisingChannelSubTypeEnum\AdvertisingChannelSubType;
use Google\Ads\GoogleAds\V15\Enums\BudgetTypeEnum\BudgetType;
use Google\Ads\GoogleAds\V15\Services\CampaignBudgetService;
use Google\Ads\GoogleAds\V15\Services\CampaignService;
use Google\Ads\GoogleAds\V15\Services\CampaignOperation;
use Google\Ads\GoogleAds\V15\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V15\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V15\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V15\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateVideoCampaign extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a new Google Ads Video campaign under a specified customer account.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param array $campaignData Campaign details including businessName, budget, startDate, endDate, etc.
     * @return string|null The resource name of the created campaign, or null on failure.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        // 1. Create Campaign Budget
        $campaignBudgetResourceName = $this->createCampaignBudget($customerId, $campaignData['budget']);
        if (is_null($campaignBudgetResourceName)) {
            $this->logError("Failed to create campaign budget for customer $customerId.");
            return null;
        }

        // 2. Create Campaign
        $campaign = new Campaign([
            'name' => $campaignData['businessName'] . ' Video Campaign - ' . uniqid(),
            'advertising_channel_type' => AdvertisingChannelType::VIDEO,
            'advertising_channel_sub_type' => AdvertisingChannelSubType::VIDEO_RESPONSIVE,
            'campaign_budget' => $campaignBudgetResourceName,
            'status' => CampaignStatus::PAUSED, // Start paused to allow further configuration
            'start_date' => $campaignData['startDate'],
            'end_date' => $campaignData['endDate'],
            'maximize_conversions' => new MaximizeConversions(), // Default for video campaigns
        ]);

        $campaignOperation = new CampaignOperation();
        $campaignOperation->create = $campaign;

        try {
            $campaignServiceClient = $this->client->getCampaignServiceClient();
            $response = $campaignServiceClient->mutateCampaigns($customerId, [$campaignOperation]);
            $newCampaignResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Video campaign: " . $newCampaignResourceName);
            return $newCampaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Video campaign for customer $customerId: " . $e->getMessage(), $e);
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
}
