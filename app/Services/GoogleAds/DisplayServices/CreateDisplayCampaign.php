<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Models\Customer;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\Support\CampaignDateTimes;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V26\Common\TargetCpa;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BudgetTypeEnum\BudgetType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V26\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V26\Services\CampaignOperation;

class CreateDisplayCampaign extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a new Google Ads Display campaign under a specified customer account.
     *
     * @param  string  $customerId  The Google Ads customer ID.
     * @param  array  $campaignData  Campaign details including businessName, budget, startDate, endDate, etc.
     * @return string|null The resource name of the created campaign, or null on failure.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        $bidding = DisplayBiddingConfiguration::desired(['name' => $campaignData['biddingStrategyType'] ?? null,
            'parameters' => ['targetCpaMicros' => $campaignData['targetCpaMicros'] ?? null]]);
        if ($bidding === null) {
            $this->logError('Display creation needs a supported explicit bidding strategy. Manual CPC requires a separate reviewed Display estimate.');

            return null;
        }
        $budgetMicros = CampaignSpendGuardrails::moneyToMicros($campaignData['budget'] ?? null);
        if ($budgetMicros <= 0) {
            $this->logError('A positive daily budget is required for Display creation.');

            return null;
        }
        $this->ensureClient();
        // Check if a campaign with the same name already exists.
        $campaignName = $campaignData['businessName'].' Display Campaign';
        $existingCampaign = $this->getCampaignByName($customerId, $campaignName);
        if ($existingCampaign) {
            $this->logInfo("Campaign '{$campaignName}' already exists. Skipping creation.");

            return $existingCampaign->getResourceName();
        }

        // 1. Create Campaign Budget
        $campaignBudgetResourceName = $this->createCampaignBudget($customerId, $budgetMicros);
        if (is_null($campaignBudgetResourceName)) {
            $this->logError("Failed to create campaign budget for customer $customerId.");

            return null;
        }

        // 2. Create Campaign
        $campaign = new Campaign([
            'name' => $campaignName,
            'advertising_channel_type' => AdvertisingChannelType::DISPLAY,
            'campaign_budget' => $campaignBudgetResourceName,
            'status' => CampaignStatus::PAUSED,
            'start_date_time' => CampaignDateTimes::startOfDay($campaignData['startDate']),
            'end_date_time' => CampaignDateTimes::endOfDay($campaignData['endDate']),
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
        ]);

        if ($bidding['type'] === 'TARGET_CPA') {
            $campaign->setTargetCpa(new TargetCpa([
                'target_cpa_micros' => $bidding['target_cpa_micros'],
            ]));
        } else {
            $campaign->setMaximizeConversions(new MaximizeConversions);
        }

        $campaignOperation = new CampaignOperation;
        $campaignOperation->setCreate($campaign);

        try {
            $campaignServiceClient = $this->client->getCampaignServiceClient();
            // Fix: Use MutateCampaignsRequest object
            $request = new \Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [$campaignOperation],
            ]);
            $response = $campaignServiceClient->mutateCampaigns($request);
            $newCampaignResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo('Successfully created Display campaign: '.$newCampaignResourceName);

            return $newCampaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Display campaign for customer $customerId: ".$e->getMessage(), $e);

            return null;
        }
    }

    /**
     * Creates a campaign budget for a new campaign.
     *
     * @param  string  $customerId  The Google Ads customer ID.
     * @param  int  $budgetMicros  The daily budget in integer micros.
     * @return string|null The resource name of the created campaign budget, or null on failure.
     */
    private function createCampaignBudget(string $customerId, int $budgetMicros): ?string
    {
        $campaignBudget = new CampaignBudget([
            'name' => 'Daily Budget - '.uniqid(),
            'amount_micros' => $budgetMicros,
            'delivery_method' => BudgetType::STANDARD,
            'explicitly_shared' => false,
        ]);

        $campaignBudgetOperation = new CampaignBudgetOperation;
        $campaignBudgetOperation->setCreate($campaignBudget);

        try {
            $campaignBudgetServiceClient = $this->client->getCampaignBudgetServiceClient();
            // Fix: Use MutateCampaignBudgetsRequest object
            $request = new \Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [$campaignBudgetOperation],
            ]);
            $response = $campaignBudgetServiceClient->mutateCampaignBudgets($request);
            $newBudgetResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo('Successfully created campaign budget: '.$newBudgetResourceName);

            return $newBudgetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating campaign budget for customer $customerId: ".$e->getMessage(), $e);

            return null;
        }
    }

    private function getCampaignByName(string $customerId, string $campaignName): ?Campaign
    {
        $query = "SELECT campaign.resource_name, campaign.name FROM campaign WHERE campaign.name = '{$campaignName}'";
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            // Fix: Use SearchGoogleAdsRequest object instead of passing arguments directly
            $request = new \Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ]);
            $response = $googleAdsServiceClient->search($request);

            foreach ($response->getIterator() as $googleAdsRow) {
                return $googleAdsRow->getCampaign();
            }
        } catch (GoogleAdsException $e) {
            $this->logError("Error fetching campaign by name for customer $customerId: ".$e->getMessage(), $e);
            throw $e;
        }

        return null;
    }
}
