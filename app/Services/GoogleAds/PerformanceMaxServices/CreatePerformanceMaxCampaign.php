<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelSubTypeEnum\AdvertisingChannelSubType;
use Google\Ads\GoogleAds\V22\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use App\Services\GoogleAds\CreateCampaignBudget;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;
use App\Services\CampaignStatusHelper;

class CreatePerformanceMaxCampaign extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        $this->ensureClient();

        // 1. Create Budget
        // Note: CreateCampaignBudget inherits BaseGoogleAdsService, so we pass useMccCredentials
        $budgetService = new CreateCampaignBudget($this->customer, $this->useMccCredentials);
        
        // Ensure budget is a multiple of 10000 (1 cent) to avoid NON_MULTIPLE_OF_MINIMUM_CURRENCY_UNIT error
        $dailyBudgetMicros = (int)($campaignData['budget'] * 1000000);
        $dailyBudgetMicros = round($dailyBudgetMicros / 10000) * 10000;

        $budgetResourceName = ($budgetService)(
            $customerId, 
            'Budget for ' . $campaignData['businessName'], 
            $dailyBudgetMicros,
            false // Not explicitly shared for PMax to avoid BIDDING_STRATEGY_TYPE_INCOMPATIBLE_WITH_SHARED_BUDGET
        );

        if (!$budgetResourceName) {
            return null;
        }

        // 2. Create Campaign
        $campaign = new Campaign([
            'name' => $campaignData['businessName'],
            'status' => CampaignStatusHelper::getGoogleAdsStatus(), // Status based on testing mode config
            'advertising_channel_type' => AdvertisingChannelType::PERFORMANCE_MAX,
            // 'advertising_channel_sub_type' => AdvertisingChannelSubType::UNKNOWN, // Removed as it causes INVALID_ENUM_VALUE
            'campaign_budget' => $budgetResourceName,
            'start_date' => str_replace('-', '', $campaignData['startDate']),
            'end_date' => str_replace('-', '', $campaignData['endDate']),
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
            'brand_guidelines_enabled' => false, // Disable Brand Guidelines to avoid CampaignAsset requirements
            // 'url_expansion_opt_out' => false, // Removed as it's not a direct property of Campaign in V22 or handled differently
        ]);

        // Set Bidding Strategy (PMax defaults to Maximize Conversions or Maximize Conversion Value)
        // For simplicity, we'll use Maximize Conversions with optional Target CPA
        if (isset($campaignData['targetCpaMicros'])) {
            $campaign->setMaximizeConversions(new \Google\Ads\GoogleAds\V22\Common\MaximizeConversions([
                'target_cpa_micros' => $campaignData['targetCpaMicros']
            ]));
        } else {
            $campaign->setMaximizeConversions(new \Google\Ads\GoogleAds\V22\Common\MaximizeConversions());
        }

        $campaignOperation = new CampaignOperation();
        $campaignOperation->setCreate($campaign);

        try {
            $campaignServiceClient = $this->client->getCampaignServiceClient();
            $response = $campaignServiceClient->mutateCampaigns(new MutateCampaignsRequest([
                'customer_id' => $customerId,
                'operations' => [$campaignOperation],
            ]));

            $campaignResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Performance Max campaign: $campaignResourceName");

            return $campaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create Performance Max campaign: " . $e->getMessage());
            return null;
        }
    }
}
