<?php

namespace App\Services\GoogleAds\SearchServices;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\Support\CampaignDateTimes;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Common\ManualCpc;
use Google\Ads\GoogleAds\V26\Common\TargetCpa;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\AssetAutomationStatusEnum\AssetAutomationStatus;
use Google\Ads\GoogleAds\V26\Enums\AssetAutomationTypeEnum\AssetAutomationType;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V26\Enums\BudgetPeriodEnum\BudgetPeriod;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\EuPoliticalAdvertisingStatusEnum\EuPoliticalAdvertisingStatus;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V26\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V26\Services\CampaignOperation;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateOperation;

class CreateSearchCampaign extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a new Google Ads Search campaign under a specified customer account.
     *
     * @param  string  $customerId  The Google Ads customer ID.
     * @param  array  $campaignData  Campaign details including businessName, budget, startDate, endDate, etc.
     * @return string|null The resource name of the created campaign, or null on failure.
     */
    public function __invoke(string $customerId, array $campaignData): ?string
    {
        $totalBudget = $campaignData['google_total_budget'] ?? null;
        if (array_key_exists('google_total_budget', $campaignData) && (
            ! is_array($totalBudget) || ! is_int($totalBudget['total_micros'] ?? null)
            || $totalBudget['total_micros'] <= 0 || $totalBudget['total_micros'] % 10_000 !== 0
            || ! is_string($campaignData['startDate'] ?? null) || ($totalBudget['start_date'] ?? null) !== $campaignData['startDate']
            || ! is_string($campaignData['endDate'] ?? null) || ($totalBudget['end_date'] ?? null) !== $campaignData['endDate']
            || ! is_string($totalBudget['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $totalBudget['currency'])
            || ! ctype_digit($customerId) || $this->customer?->cleanGoogleCustomerId() !== $customerId
        )) {
            throw new \InvalidArgumentException('A Search total budget requires approved amounts and dates in the same Google account.');
        }

        // Ensure client is initialized before proceeding
        $this->ensureClient();

        // Check if a campaign with the same name already exists.
        $campaignName = $campaignData['businessName'].' Search Campaign';
        $existingCampaign = $this->getCampaignByName($customerId, $campaignName, failOnReadError: $totalBudget !== null);
        if ($existingCampaign) {
            $resource = $existingCampaign->getResourceName();
            if ($totalBudget !== null && ! app(ReadCampaignConfiguration::class, ['customer' => $this->customer])
                ->hasVerifiedSearchTotalBudget($customerId, $resource, $totalBudget)) {
                throw new \RuntimeException('The existing Search campaign does not match the approved total budget and schedule.');
            }
            $this->logInfo("Campaign '{$campaignName}' already exists. Skipping creation.");

            return $resource;
        }

        // A total budget and its campaign are created together using a temporary resource name.
        $campaignBudgetResourceName = $totalBudget === null
            ? $this->createCampaignBudget($customerId, $campaignData['budget'])
            : "customers/{$customerId}/campaignBudgets/-1";
        if (is_null($campaignBudgetResourceName)) {
            $this->logError("Failed to create campaign budget for customer $customerId.");

            return null;
        }

        // 2. Create Campaign
        $campaign = new Campaign([
            'name' => $campaignName,
            'advertising_channel_type' => AdvertisingChannelType::SEARCH,
            'campaign_budget' => $campaignBudgetResourceName,
            // Every caller stages Search paused. Only the separate verified
            // activation path may enable it after bids, ads and goals are checked.
            'status' => CampaignStatus::PAUSED,
            'start_date_time' => CampaignDateTimes::startOfDay($campaignData['startDate']),
            'end_date_time' => CampaignDateTimes::endOfDay($campaignData['endDate']),
            'manual_cpc' => new ManualCpc, // Default for search campaigns
            'geo_target_type_setting' => new Campaign\GeoTargetTypeSetting([
                'positive_geo_target_type' => \Google\Ads\GoogleAds\V26\Enums\PositiveGeoTargetTypeEnum\PositiveGeoTargetType::PRESENCE,
            ]),
            'network_settings' => new Campaign\NetworkSettings([
                'target_google_search' => true,
                'target_search_network' => false,
                'target_content_network' => false,
            ]),
            'contains_eu_political_advertising' => EuPoliticalAdvertisingStatus::DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING,
        ]);

        if (($campaignData['single_landing_page'] ?? false) === true) {
            $campaign->setExcludedParentAssetFieldTypes([AssetFieldType::SITELINK]);
            $campaign->setAssetAutomationSettings([new Campaign\AssetAutomationSetting([
                'asset_automation_type' => AssetAutomationType::FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION,
                'asset_automation_status' => AssetAutomationStatus::OPTED_OUT,
            ])]);
        }

        // Apply bidding strategy (example: TargetCpa)
        if (isset($campaignData['biddingStrategyType']) && $campaignData['biddingStrategyType'] === 'TARGET_CPA') {
            $campaign->setTargetCpa(new TargetCpa([
                'target_cpa_micros' => $campaignData['targetCpaMicros'] ?? null,
            ]));
        }

        $campaignOperation = new CampaignOperation;
        $campaignOperation->setCreate($campaign);

        if ($totalBudget !== null) {
            return $this->createWithTotalBudget($customerId, $campaignOperation, $campaignBudgetResourceName, $campaignName, $totalBudget['total_micros']);
        }

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
            $this->logInfo('Successfully created Search campaign: '.$newCampaignResourceName);

            return $newCampaignResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Search campaign for customer $customerId: ".$e->getMessage(), $e);

            return null;
        }
    }

    private function createWithTotalBudget(string $customerId, CampaignOperation $campaign, string $budgetResource, string $name, int $totalMicros): ?string
    {
        $budget = new CampaignBudget([
            'resource_name' => $budgetResource,
            'name' => 'Budget for '.$name,
            'period' => BudgetPeriod::CUSTOM_PERIOD,
            'total_amount_micros' => $totalMicros,
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => false,
        ]);
        $response = $this->client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
            'validate_only' => $this->dryRun,
            'customer_id' => $customerId,
            'partial_failure' => false,
            'mutate_operations' => [
                new MutateOperation(['campaign_budget_operation' => new CampaignBudgetOperation(['create' => $budget])]),
                new MutateOperation(['campaign_operation' => $campaign]),
            ],
        ]));

        $results = $response->getMutateOperationResponses();
        $resource = count($results) === 2 ? $results[1]->getCampaignResult()?->getResourceName() : null;

        return $resource && preg_match('#^customers/'.$customerId.'/campaigns/\d+$#D', $resource) ? $resource : null;
    }

    /**
     * Creates a campaign budget for a new campaign.
     *
     * @param  string  $customerId  The Google Ads customer ID.
     * @param  float  $budgetAmount  The daily budget amount.
     * @return string|null The resource name of the created campaign budget, or null on failure.
     */
    private function createCampaignBudget(string $customerId, float $budgetAmount): ?string
    {
        $this->ensureClient();

        $dailyBudgetMicros = (int) round($budgetAmount * 1_000_000);
        $dailyBudgetMicros = (int) (round($dailyBudgetMicros / 10_000) * 10_000); // Must be multiple of minimum currency unit

        $campaignBudget = new CampaignBudget([
            'name' => 'Search Budget - '.uniqid(),
            'amount_micros' => $dailyBudgetMicros,
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
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

    /**
     * Check if a campaign with the given name already exists
     */
    private function getCampaignByName(string $customerId, string $campaignName, bool $failOnReadError = false): ?Campaign
    {
        $this->ensureClient();

        $escapedName = str_replace(['\\', "'"], ['\\\\', "\\'"], $campaignName);
        $query = "SELECT campaign.resource_name, campaign.name FROM campaign WHERE campaign.name = '{$escapedName}'";
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
            if ($failOnReadError) {
                throw $e;
            }
            $this->logError("Error fetching campaign by name for customer $customerId: ".$e->getMessage(), $e);
        }

        return null;
    }
}
