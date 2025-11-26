<?php

namespace App\Services\GoogleAds\SearchServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\BudgetTypeEnum\BudgetType;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetService;
use Google\Ads\GoogleAds\V22\Services\CampaignService;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V22\Common\TargetCpa;
use Google\Ads\GoogleAds\V22\Common\ManualCpc;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;
use Google\Ads\GoogleAds\V22\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;

class CreateSearchCampaign extends BaseGoogleAdsService
{
    public function __construct(Customer $customer, bool $useMccCredentials = false)
    {
        parent::__construct($customer, $useMccCredentials);
    }

    /**
     * Creates a new Google Ads Search campaign under a specified customer account.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param array $campaignData Campaign details including businessName, budget, startDate, endDate, etc.
     * @return string|null The resource name of the created campaign, or null on failure.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        // Ensure client is initialized before proceeding
        $this->ensureClient();
        
        // Check if a campaign with the same name already exists.
        $campaignName = $campaignData['businessName'] . ' Search Campaign';
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
            'advertising_channel_type' => AdvertisingChannelType::SEARCH,
            'campaign_budget' => $campaignBudgetResourceName,
            'status' => CampaignStatus::PAUSED, // Start paused to allow further configuration
            'start_date' => $campaignData['startDate'],
            'end_date' => $campaignData['endDate'],
            'manual_cpc' => new ManualCpc(), // Default for search campaigns
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
        ]);

        // Apply bidding strategy (example: TargetCpa)
        if (isset($campaignData['biddingStrategyType']) && $campaignData['biddingStrategyType'] === 'TARGET_CPA') {
            $campaign->setTargetCpa(new TargetCpa([
                'target_cpa_micros' => $campaignData['targetCpaMicros'] ?? null,
            ]));
        }

        $campaignOperation = new CampaignOperation();
        $campaignOperation->setCreate($campaign);

        try {
            $campaignServiceClient = $this->client->getCampaignServiceClient();
            // Fix: Use MutateCampaignsRequest object
            $request = new \Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest([
                'customer_id' => $customerId,
                'operations' => [$campaignOperation],
            ]);
            $response = $campaignServiceClient->mutateCampaigns($request);
            $newCampaignResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Search campaign: " . $newCampaignResourceName);
            return $newCampaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Search campaign for customer $customerId: " . $e->getMessage(), $e);
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
        $this->ensureClient();
        
        $campaignBudget = new CampaignBudget([
            'name' => 'Search Budget - ' . uniqid(),
            'amount_micros' => (int) ($budgetAmount * 1_000_000), // Convert to micros
            'delivery_method' => BudgetType::STANDARD,
            'explicitly_shared' => false
        ]);

        $campaignBudgetOperation = new CampaignBudgetOperation();
        $campaignBudgetOperation->setCreate($campaignBudget);

        try {
            $campaignBudgetServiceClient = $this->client->getCampaignBudgetServiceClient();
            // Fix: Use MutateCampaignBudgetsRequest object
            $request = new \Google\Ads\GoogleAds\V22\Services\MutateCampaignBudgetsRequest([
                'customer_id' => $customerId,
                'operations' => [$campaignBudgetOperation],
            ]);
            $response = $campaignBudgetServiceClient->mutateCampaignBudgets($request);
            $newBudgetResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created campaign budget: " . $newBudgetResourceName);
            return $newBudgetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating campaign budget for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }

    /**
     * Check if a campaign with the given name already exists
     */
    private function getCampaignByName(string $customerId, string $campaignName): ?Campaign
    {
        $this->ensureClient();
        
        $query = "SELECT campaign.resource_name, campaign.name FROM campaign WHERE campaign.name = '{$campaignName}'";
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            // Fix: Use SearchGoogleAdsRequest object instead of passing arguments directly
            $request = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ]);
            $response = $googleAdsServiceClient->search($request);

            foreach ($response->getIterator() as $googleAdsRow) {
                return $googleAdsRow->getCampaign();
            }
        } catch (GoogleAdsException $e) {
            $this->logError("Error fetching campaign by name for customer $customerId: " . $e->getMessage(), $e);
        }

        return null;
    }
}
