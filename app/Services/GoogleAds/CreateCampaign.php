<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignServiceClient;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;

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
        // 1. Create the Campaign Budget
        $budgetResourceName = ($this->createCampaignBudget)(
            $customerId,
            $campaignData['businessName'] . ' Budget',
            $campaignData['budget'] * 1000000 // Convert to micros
        );

        if (!$budgetResourceName) {
            Log::error('Google Ads API Error: Failed to create budget, cannot proceed with campaign creation.');
            return null;
        }

        // 2. Create the Campaign
        $campaign = new Campaign([
            'name' => $campaignData['businessName'],
            'advertising_channel_type' => \Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType::SEARCH,
            'status' => \Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus::PAUSED,
            'campaign_budget' => $budgetResourceName,
        ]);

        $campaignOperation = new CampaignOperation();
        $campaignOperation->setCreate($campaign);

        /** @var CampaignServiceClient $campaignServiceClient */
        $campaignServiceClient = $this->googleAdsClient->getCampaignServiceClient();
        $response = $campaignServiceClient->mutateCampaigns($customerId, [$campaignOperation]);

        return $response->getResults() ? $response->getResults()[0]->getResourceName() : null;
    }
}
