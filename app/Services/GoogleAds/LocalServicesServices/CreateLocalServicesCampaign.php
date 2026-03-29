<?php

namespace App\Services\GoogleAds\LocalServicesServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Resources\Campaign\LocalServicesCampaignSettings;
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

class CreateLocalServicesCampaign extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a new Google Ads Local Services campaign.
     *
     * Local Services Ads appear at the top of Google Search for local service queries
     * (plumbers, electricians, lawyers, etc.). Ads are auto-generated from the business
     * profile — no ad creatives or ad groups are needed.
     *
     * Prerequisites:
     * - Business must be verified through Local Services Ads program
     * - Business profile must be complete with license/insurance verification
     *
     * @param string $customerId The Google Ads customer ID.
     * @param array $campaignData Campaign details:
     *   - businessName: string
     *   - budget: float (daily budget in dollars)
     *   - startDate: string (Y-m-d)
     *   - endDate: string (Y-m-d)
     *   - categoryBids: array (optional, array of ['category_id' => string, 'manual_cpa_bid_micros' => int])
     * @return string|null The resource name of the created campaign, or null on failure.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        $this->ensureClient();

        // 1. Create Campaign Budget
        $campaignBudgetResourceName = $this->createCampaignBudget($customerId, $campaignData['budget']);
        if (is_null($campaignBudgetResourceName)) {
            $this->logError("Failed to create campaign budget for Local Services campaign, customer $customerId.");
            return null;
        }

        // 2. Create Campaign
        $campaignParams = [
            'name' => $campaignData['businessName'] . ' Local Services Campaign - ' . uniqid(),
            'advertising_channel_type' => AdvertisingChannelType::LOCAL_SERVICES,
            'campaign_budget' => $campaignBudgetResourceName,
            'status' => CampaignStatusHelper::getGoogleAdsStatus(),
            'start_date' => $campaignData['startDate'],
            'end_date' => $campaignData['endDate'],
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
        ];

        // Local Services uses maximize conversions by default
        $campaign = new Campaign($campaignParams);
        $campaign->setMaximizeConversions(new MaximizeConversions());

        // Optional: configure category bids
        if (!empty($campaignData['categoryBids'])) {
            $categoryBids = [];
            foreach ($campaignData['categoryBids'] as $bid) {
                $categoryBids[] = new \Google\Ads\GoogleAds\V22\Resources\Campaign\CategoryBid([
                    'category_id' => $bid['category_id'],
                    'manual_cpa_bid_micros' => $bid['manual_cpa_bid_micros'] ?? null,
                ]);
            }

            $localServicesSettings = new LocalServicesCampaignSettings([
                'category_bids' => $categoryBids,
            ]);
            $campaign->setLocalServicesCampaignSettings($localServicesSettings);
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
            $this->logInfo("Successfully created Local Services campaign: " . $newCampaignResourceName);
            return $newCampaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Local Services campaign for customer $customerId: " . $e->getMessage(), $e);
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
            $this->logInfo("Successfully created Local Services campaign budget: " . $newBudgetResourceName);
            return $newBudgetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Local Services campaign budget for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
