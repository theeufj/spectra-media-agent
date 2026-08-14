<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use App\Services\CampaignStatusHelper;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Illuminate\Support\Facades\Log;

class CreateCampaign extends BaseGoogleAdsService
{
    private CreateCampaignBudget $createCampaignBudget;

    public function __construct(Customer $customer, CreateCampaignBudget $createCampaignBudget)
    {
        parent::__construct($customer);
        $this->createCampaignBudget = $createCampaignBudget;
    }

    public function __invoke(string $customerId, array $campaignData): ?array
    {
        // Builds $this->client. The budget service below is a separate instance
        // with its own client, so a budget succeeding says nothing about whether
        // this instance can reach the API.
        $this->ensureClient();

        // 1. Create the Campaign Budget
        $budgetResourceName = ($this->createCampaignBudget)(
            $customerId,
            $campaignData['businessName'].' Budget',
            $campaignData['budget'] * 1000000 // Convert to micros
        );

        if (! $budgetResourceName) {
            Log::error('Google Ads API Error: Failed to create budget, cannot proceed with campaign creation.');

            return null;
        }

        // 2. Create the Campaign
        $campaign = new Campaign([
            'name' => $campaignData['businessName'],
            'advertising_channel_type' => \Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType::SEARCH,
            'status' => CampaignStatusHelper::getGoogleAdsStatus(),
            'campaign_budget' => $budgetResourceName,
        ]);

        $campaignOperation = new CampaignOperation;
        $campaignOperation->setCreate($campaign);

        /** @var CampaignServiceClient $campaignServiceClient */
        $campaignServiceClient = $this->client->getCampaignServiceClient();
        $request = new MutateCampaignsRequest([
            'validate_only' => $this->dryRun,
            'customer_id' => $customerId,
            'operations' => [$campaignOperation],
        ]);
        $response = $campaignServiceClient->mutateCampaigns($request);

        // getResults() returns a RepeatedField, which is an object and therefore
        // always truthy — the guard it used to sit behind never fired, so an
        // empty response would index [0] on nothing. Count the entries instead.
        $results = $response->getResults();

        return count($results) > 0 ? $results[0]->getResourceName() : null;
    }
}
