<?php

namespace App\Services\GoogleAds\ShoppingServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Resources\Campaign\ShoppingSetting;
use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\BudgetTypeEnum\BudgetType;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V22\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V22\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;
use App\Services\CampaignStatusHelper;

class CreateShoppingCampaign extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a new Google Ads Standard Shopping campaign.
     *
     * Shopping campaigns display product information directly from the Google Merchant Center
     * feed. Requires a linked Merchant Center account.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param array $campaignData Campaign details:
     *   - businessName: string
     *   - budget: float (daily budget in dollars)
     *   - startDate: string (Y-m-d)
     *   - endDate: string (Y-m-d)
     *   - merchantId: int (Google Merchant Center ID)
     *   - feedLabel: string (optional, e.g. country code 'US')
     *   - campaignPriority: int (0=low, 1=medium, 2=high, default 0)
     *   - enableLocal: bool (optional, enable local inventory ads)
     *   - targetCpaMicros: int (optional)
     * @return string|null The resource name of the created campaign, or null on failure.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        $this->ensureClient();

        if (empty($campaignData['merchantId'])) {
            $this->logError("Merchant Center ID is required for Shopping campaigns.");
            return null;
        }

        // 1. Create Campaign Budget
        $campaignBudgetResourceName = $this->createCampaignBudget($customerId, $campaignData['budget']);
        if (is_null($campaignBudgetResourceName)) {
            $this->logError("Failed to create campaign budget for Shopping campaign, customer $customerId.");
            return null;
        }

        // 2. Configure Shopping Settings
        $shoppingSetting = new ShoppingSetting([
            'merchant_id' => (int) $campaignData['merchantId'],
            'campaign_priority' => $campaignData['campaignPriority'] ?? 0,
            'enable_local' => $campaignData['enableLocal'] ?? false,
        ]);

        if (!empty($campaignData['feedLabel'])) {
            $shoppingSetting->setFeedLabel($campaignData['feedLabel']);
        }

        // 3. Create Campaign
        $campaign = new Campaign([
            'name' => $campaignData['businessName'] . ' Shopping Campaign - ' . uniqid(),
            'advertising_channel_type' => AdvertisingChannelType::SHOPPING,
            'shopping_setting' => $shoppingSetting,
            'campaign_budget' => $campaignBudgetResourceName,
            'status' => CampaignStatusHelper::getGoogleAdsStatus(),
            'start_date' => $campaignData['startDate'],
            'end_date' => $campaignData['endDate'],
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
        ]);

        // Set bidding strategy
        if (isset($campaignData['targetCpaMicros'])) {
            $campaign->setMaximizeConversions(new MaximizeConversions([
                'target_cpa_micros' => $campaignData['targetCpaMicros'],
            ]));
        } else {
            $campaign->setMaximizeConversions(new MaximizeConversions());
        }

        $campaignOperation = new CampaignOperation();
        $campaignOperation->setCreate($campaign);

        try {
            $campaignServiceClient = $this->client->getCampaignServiceClient();
            $request = new MutateCampaignsRequest([
                'customer_id' => $customerId,
                'operations' => [$campaignOperation],
            ]);
            $response = $campaignServiceClient->mutateCampaigns($request);
            $newCampaignResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Shopping campaign: " . $newCampaignResourceName);
            return $newCampaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Shopping campaign for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }

    private function createCampaignBudget(string $customerId, float $budgetAmount): ?string
    {
        $campaignBudget = new CampaignBudget([
            'name' => 'Daily Budget - ' . uniqid(),
            'amount_micros' => (int) ($budgetAmount * 1_000_000),
            'delivery_method' => BudgetType::STANDARD,
            'explicitly_shared' => false,
        ]);

        $campaignBudgetOperation = new CampaignBudgetOperation();
        $campaignBudgetOperation->setCreate($campaignBudget);

        try {
            $campaignBudgetServiceClient = $this->client->getCampaignBudgetServiceClient();
            $request = new MutateCampaignBudgetsRequest([
                'customer_id' => $customerId,
                'operations' => [$campaignBudgetOperation],
            ]);
            $response = $campaignBudgetServiceClient->mutateCampaignBudgets($request);
            $newBudgetResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Shopping campaign budget: " . $newBudgetResourceName);
            return $newBudgetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Shopping campaign budget for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
