<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\Support\CampaignDateTimes;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\AssetAutomationStatusEnum\AssetAutomationStatus;
use Google\Ads\GoogleAds\V26\Enums\AssetAutomationTypeEnum\AssetAutomationType;
use Google\Ads\GoogleAds\V26\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Resources\Campaign\AssetAutomationSetting;
use Google\Ads\GoogleAds\V26\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V26\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V26\Services\CampaignOperation;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateOperation;

class CreatePerformanceMaxCampaign extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        if ($customerId !== $this->customer->cleanGoogleCustomerId() || ! ctype_digit($customerId)) {
            throw new \InvalidArgumentException('The Performance Max budget must belong to the customer Google Ads account.');
        }
        $dailyBudgetMicros = CampaignSpendGuardrails::moneyToMicros($campaignData['budget']);
        $budgetResourceName = "customers/{$customerId}/campaignBudgets/-1";
        $budget = new CampaignBudget([
            'resource_name' => $budgetResourceName,
            'name' => 'Budget for '.$campaignData['businessName'],
            'amount_micros' => $dailyBudgetMicros,
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => false,
        ]);
        $startDate = $campaignData['startDate'] ?? now($this->customer->timezone)->toDateString();

        $campaign = new Campaign([
            'name' => $campaignData['businessName'],
            'status' => CampaignStatus::PAUSED,
            'advertising_channel_type' => AdvertisingChannelType::PERFORMANCE_MAX,
            'campaign_budget' => $budgetResourceName,
            'start_date_time' => CampaignDateTimes::startOfDay($startDate),
            ...(isset($campaignData['endDate']) ? ['end_date_time' => CampaignDateTimes::endOfDay($campaignData['endDate'])] : []),
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
            'brand_guidelines_enabled' => false, // Disable Brand Guidelines to avoid CampaignAsset requirements
            'asset_automation_settings' => array_map(fn (int $type) => new AssetAutomationSetting([
                'asset_automation_type' => $type, 'asset_automation_status' => AssetAutomationStatus::OPTED_OUT,
            ]), [AssetAutomationType::FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION, AssetAutomationType::TEXT_ASSET_AUTOMATION]),
        ]);

        // Set Bidding Strategy (PMax defaults to Maximize Conversions or Maximize Conversion Value)
        // For simplicity, we'll use Maximize Conversions with optional Target CPA
        if (isset($campaignData['targetCpaMicros'])) {
            $campaign->setMaximizeConversions(new \Google\Ads\GoogleAds\V26\Common\MaximizeConversions([
                'target_cpa_micros' => $campaignData['targetCpaMicros'],
            ]));
        } else {
            $campaign->setMaximizeConversions(new \Google\Ads\GoogleAds\V26\Common\MaximizeConversions);
        }

        try {
            $this->ensureClient();
            $response = $this->client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'partial_failure' => false,
                'mutate_operations' => [
                    new MutateOperation(['campaign_budget_operation' => new CampaignBudgetOperation(['create' => $budget])]),
                    new MutateOperation(['campaign_operation' => new CampaignOperation(['create' => $campaign])]),
                ],
            ]));

            if (count($response->getMutateOperationResponses()) !== 2) {
                return null;
            }
            $campaignResourceName = $response->getMutateOperationResponses()[1]->getCampaignResult()?->getResourceName();
            $this->logInfo("Created Performance Max campaign: $campaignResourceName");

            return $campaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError('Failed to create Performance Max campaign: '.$e->getMessage());

            return null;
        }
    }
}
