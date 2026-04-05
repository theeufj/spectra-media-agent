<?php

namespace App\Services\GoogleAds\DemandGenServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\BudgetTypeEnum\BudgetType;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V22\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V22\Common\MaximizeConversionValue;
use Google\Ads\GoogleAds\V22\Common\TargetCpa;
use Google\Ads\GoogleAds\V22\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use App\Models\Customer;
use App\Services\CampaignStatusHelper;

class CreateDemandGenCampaign extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a new Google Ads Demand Gen campaign.
     *
     * Demand Gen campaigns serve across YouTube, Gmail, and Google Discover feeds
     * using image and video assets for awareness and mid-funnel engagement.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param array $campaignData Campaign details including businessName, budget, startDate, endDate, etc.
     * @return string|null The resource name of the created campaign, or null on failure.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        $this->ensureClient();

        // 1. Create Campaign Budget
        $campaignBudgetResourceName = $this->createCampaignBudget($customerId, $campaignData['budget']);
        if (is_null($campaignBudgetResourceName)) {
            $this->logError("Failed to create campaign budget for Demand Gen campaign, customer $customerId.");
            return null;
        }

        // 2. Create Campaign
        $campaign = new Campaign([
            'name' => $campaignData['businessName'] . ' Demand Gen Campaign - ' . uniqid(),
            'advertising_channel_type' => AdvertisingChannelType::DEMAND_GEN,
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
            $this->logInfo("Successfully created Demand Gen campaign: " . $newCampaignResourceName);
            return $newCampaignResourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Error creating Demand Gen campaign for customer $customerId: " . $e->getMessage(), $e);
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
            $this->logInfo("Successfully created Demand Gen campaign budget: " . $newBudgetResourceName);
            return $newBudgetResourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Error creating Demand Gen campaign budget for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
