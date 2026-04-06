<?php

namespace App\Services\MicrosoftAds;

use Illuminate\Support\Facades\Log;

class CampaignService extends BaseMicrosoftAdsService
{
    /**
     * Create a search campaign.
     */
    public function createSearchCampaign(array $params): ?array
    {
        $campaign = [
            'BudgetType' => 'DailyBudgetStandard',
            'DailyBudget' => $params['daily_budget'] ?? 50,
            'Name' => $params['name'],
            'TimeZone' => $this->config['defaults']['time_zone'] ?? 'EasternStandardTime',
            'Status' => $params['status'] ?? 'Paused',
            'CampaignType' => 'Search',
            'Languages' => ['Language' => [$this->config['defaults']['language'] ?? 'English']],
        ];

        if (isset($params['locations'])) {
            $campaign['Settings'] = [
                'Setting' => [[
                    'Type' => 'TargetSetting',
                    'Details' => [['CriterionTypeGroup' => 'Audience', 'TargetAndBid' => true]],
                ]],
            ];
        }

        $result = $this->apiCall('AddCampaigns', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'Campaigns' => ['Campaign' => [$campaign]],
        ]);

        if ($result && isset($result['CampaignIds'])) {
            Log::info('Microsoft Ads: Created campaign', ['id' => $result['CampaignIds']]);
            return $result;
        }

        return null;
    }

    /**
     * Get campaign by ID.
     */
    public function getCampaign(string $campaignId): ?array
    {
        return $this->apiCall('GetCampaignsByIds', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'CampaignIds' => ['long' => [$campaignId]],
            'CampaignType' => 'Search',
        ]);
    }

    /**
     * Update campaign budget.
     */
    public function updateBudget(string $campaignId, float $dailyBudget): bool
    {
        $result = $this->apiCall('UpdateCampaigns', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'Campaigns' => ['Campaign' => [[
                'Id' => $campaignId,
                'DailyBudget' => $dailyBudget,
            ]]],
        ]);

        return $result !== null;
    }

    /**
     * Pause/enable campaign.
     */
    public function updateStatus(string $campaignId, string $status): bool
    {
        $result = $this->apiCall('UpdateCampaigns', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'Campaigns' => ['Campaign' => [[
                'Id' => $campaignId,
                'Status' => $status, // Active, Paused
            ]]],
        ]);

        return $result !== null;
    }
}
