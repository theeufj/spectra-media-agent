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

        // AddCampaigns answers 200 with a PartialErrors block when it rejects a
        // campaign, and a rejection carries no CampaignIds element at all.
        // Returning null here threw that block away, so the execution agent —
        // which knows how to read PartialErrors — could only report "empty
        // response from AddCampaigns" for every rejection Microsoft explained.
        if ($result !== null) {
            Log::warning('Microsoft Ads: AddCampaigns returned no campaign IDs', [
                'customer_id' => $this->customer->id,
                'partial_errors' => $result['PartialErrors'] ?? null,
            ]);
        }

        return $result;
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
     * Current status of a single campaign (e.g. "Active", "Paused", "BudgetPaused").
     *
     * GetCampaignsByIds returns Campaigns.Campaign as either a single object or a
     * list depending on how many IDs were requested, so both shapes are normalised.
     */
    public function getCampaignStatus(string $campaignId): ?string
    {
        $response = $this->getCampaign($campaignId);

        $campaign = $response['Campaigns']['Campaign'] ?? null;
        if ($campaign === null) {
            return null;
        }

        if (array_is_list($campaign)) {
            $campaign = $campaign[0] ?? null;
        }

        return $campaign['Status'] ?? null;
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

        return $this->accepted('UpdateCampaigns', $result, ['campaign_id' => $campaignId]);
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

        return $this->accepted('UpdateCampaigns', $result, [
            'campaign_id' => $campaignId,
            'status' => $status,
        ]);
    }

    /**
     * Did Microsoft actually apply the mutation?
     *
     * UpdateCampaigns answers 200 with a PartialErrors block when it rejects an
     * entity, so a non-null response is not evidence that anything changed —
     * `$result !== null` reported every rejected budget and status write as
     * applied. Same trap MicrosoftAdsExecutionAgent documents for the Add*
     * operations.
     */
    protected function accepted(string $operation, ?array $result, array $context = []): bool
    {
        if ($result === null) {
            return false;
        }

        if (empty($result['PartialErrors'])) {
            return true;
        }

        Log::warning("Microsoft Ads: {$operation} rejected", $context + [
            'customer_id' => $this->customer->id,
            'partial_errors' => $result['PartialErrors'],
        ]);

        return false;
    }
}
