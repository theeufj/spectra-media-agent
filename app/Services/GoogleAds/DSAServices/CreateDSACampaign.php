<?php

namespace App\Services\GoogleAds\DSAServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\ManualCpc;
use Google\Ads\GoogleAds\V22\Common\DynamicSearchAdsSetting;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreateDSACampaign extends BaseGoogleAdsService
{
    /**
     * Create a Dynamic Search Ads campaign targeting a customer's website.
     *
     * @param string $customerId
     * @param string $campaignName
     * @param string $domainName   e.g. 'example.com' (no https://)
     * @param string $languageCode e.g. 'en'
     * @param float  $dailyBudget  In account currency
     * @return string|null  Campaign resource name
     */
    public function __invoke(
        string $customerId,
        string $campaignName,
        string $domainName,
        string $languageCode,
        float  $dailyBudget
    ): ?string {
        $this->ensureClient();

        // Idempotency: skip if already exists
        $existing = $this->findByName($customerId, $campaignName);
        if ($existing) {
            $this->logInfo("DSA campaign '{$campaignName}' already exists: {$existing}");
            return $existing;
        }

        // 1. Budget
        $budgetMicros = (int) round($dailyBudget * 1_000_000);
        $budget = new CampaignBudget([
            'name'           => $campaignName . ' Budget',
            'amount_micros'  => $budgetMicros,
            'explicitly_shared' => false,
        ]);

        $budgetOp = new CampaignBudgetOperation();
        $budgetOp->setCreate($budget);

        try {
            $budgetResponse = $this->client->getCampaignBudgetServiceClient()->mutateCampaignBudgets(
                new MutateCampaignBudgetsRequest(['customer_id' => $customerId, 'operations' => [$budgetOp]])
            );
            $budgetResource = $budgetResponse->getResults()[0]->getResourceName();
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('CreateDSACampaign: Failed to create budget: ' . $e->getMessage());
            return null;
        }

        // 2. Campaign with DynamicSearchAdsSetting
        $dsaSettings = new DynamicSearchAdsSetting([
            'domain_name'          => $domainName,
            'language_code'        => $languageCode,
            'use_supplied_urls_only' => true,
        ]);

        $campaign = new Campaign([
            'name'                       => $campaignName,
            'advertising_channel_type'   => AdvertisingChannelType::SEARCH,
            'status'                     => CampaignStatus::ENABLED,
            'campaign_budget'            => $budgetResource,
            'manual_cpc'                 => new ManualCpc(['enhanced_cpc_enabled' => true]),
            'dynamic_search_ads_setting' => $dsaSettings,
        ]);

        $campaignOp = new CampaignOperation();
        $campaignOp->setCreate($campaign);

        try {
            $campaignResponse = $this->client->getCampaignServiceClient()->mutateCampaigns(
                new MutateCampaignsRequest(['customer_id' => $customerId, 'operations' => [$campaignOp]])
            );
            $resourceName = $campaignResponse->getResults()[0]->getResourceName();
            $this->logInfo("Created DSA campaign: {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('CreateDSACampaign: Failed to create campaign: ' . $e->getMessage());
            return null;
        }
    }

    private function findByName(string $customerId, string $name): ?string
    {
        try {
            $query = "SELECT campaign.resource_name FROM campaign WHERE campaign.name = '" . addslashes($name) . "' AND campaign.status != 'REMOVED' LIMIT 1";
            $response = $this->searchQuery($customerId, $query);
            foreach ($response->getIterator() as $row) {
                return $row->getCampaign()->getResourceName();
            }
        } catch (\Exception $e) {
            $this->logError('CreateDSACampaign::findByName: ' . $e->getMessage());
        }
        return null;
    }
}
