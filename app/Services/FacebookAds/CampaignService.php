<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class CampaignService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * List all campaigns for an ad account.
     *
     * @param string $accountId The Facebook ad account ID (without 'act_' prefix)
     * @return ?array
     */
    public function listCampaigns(string $accountId): ?array
    {
        try {
            $response = $this->get("/act_{$accountId}/campaigns", [
                'fields' => 'id,name,status,objective,daily_budget,lifetime_budget,start_time,end_time,created_time,updated_time',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved campaigns for ad account {$accountId}", [
                    'customer_id' => $this->customer->id,
                    'campaign_count' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error listing campaigns: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Create a new campaign.
     *
     * @param string $accountId The Facebook ad account ID (without 'act_' prefix)
     * @param string $campaignName Campaign name
     * @param string $objective Campaign objective (e.g., 'LINK_CLICKS', 'CONVERSIONS', 'REACH')
     * @param int $dailyBudget Daily budget in cents (e.g., 50000 for $500)
     * @param string $status Campaign status ('ACTIVE', 'PAUSED')
     * @return ?array
     */
    public function createCampaign(
        string $accountId,
        string $campaignName,
        string $objective = 'LINK_CLICKS',
        int $dailyBudget = 50000,
        string $status = 'PAUSED'
    ): ?array {
        try {
            $response = $this->post("/act_{$accountId}/campaigns", [
                'name' => $campaignName,
                'objective' => $objective,
                'daily_budget' => $dailyBudget,
                'status' => $status,
            ]);

            if ($response && isset($response['id'])) {
                Log::info("Created campaign for ad account {$accountId}", [
                    'customer_id' => $this->customer->id,
                    'campaign_id' => $response['id'],
                    'campaign_name' => $campaignName,
                ]);
                return $response;
            }

            Log::error("Failed to create campaign", [
                'customer_id' => $this->customer->id,
                'account_id' => $accountId,
                'response' => $response,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Error creating campaign: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'campaign_name' => $campaignName,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Update a campaign.
     *
     * @param string $campaignId Campaign ID
     * @param array $updateData Data to update
     * @return bool
     */
    public function updateCampaign(string $campaignId, array $updateData): bool
    {
        try {
            $response = $this->put("/{$campaignId}", $updateData);

            if ($response && isset($response['success']) && $response['success']) {
                Log::info("Updated campaign {$campaignId}", [
                    'customer_id' => $this->customer->id,
                ]);
                return true;
            }

            Log::error("Failed to update campaign", [
                'customer_id' => $this->customer->id,
                'campaign_id' => $campaignId,
                'response' => $response,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("Error updating campaign: " . $e->getMessage(), [
                'exception' => $e,
                'campaign_id' => $campaignId,
                'customer_id' => $this->customer->id,
            ]);
            return false;
        }
    }

    /**
     * Get campaign performance insights.
     *
     * @param string $campaignId Campaign ID
     * @param string $dateStart Start date (YYYY-MM-DD)
     * @param string $dateEnd End date (YYYY-MM-DD)
     * @return ?array
     */
    public function getCampaignInsights(string $campaignId, string $dateStart, string $dateEnd): ?array
    {
        try {
            $response = $this->get("/{$campaignId}/insights", [
                'time_range' => json_encode(['since' => $dateStart, 'until' => $dateEnd]),
                'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,ctr,cpc,conversions,conversion_rate,cost_per_action_type',
                'time_increment' => 'daily',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved insights for campaign {$campaignId}", [
                    'customer_id' => $this->customer->id,
                    'data_points' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error getting campaign insights: " . $e->getMessage(), [
                'exception' => $e,
                'campaign_id' => $campaignId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }
}
