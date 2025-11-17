<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class AdSetService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * List all ad sets for a campaign.
     *
     * @param string $campaignId Campaign ID
     * @return ?array
     */
    public function listAdSets(string $campaignId): ?array
    {
        try {
            $response = $this->get("/{$campaignId}/adsets", [
                'fields' => 'id,name,status,daily_budget,lifetime_budget,start_time,end_time,targeting,billing_event,optimization_goal',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved ad sets for campaign {$campaignId}", [
                    'customer_id' => $this->customer->id,
                    'adset_count' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error listing ad sets: " . $e->getMessage(), [
                'exception' => $e,
                'campaign_id' => $campaignId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Create a new ad set.
     *
     * @param string $campaignId Campaign ID
     * @param string $adSetName Ad set name
     * @param int $dailyBudget Daily budget in cents
     * @param array $targeting Targeting parameters
     * @param string $billingEvent Billing event (e.g., 'IMPRESSIONS', 'LINK_CLICKS', 'PURCHASE')
     * @param string $optimizationGoal Optimization goal (e.g., 'LINK_CLICKS', 'CONVERSIONS')
     * @return ?array
     */
    public function createAdSet(
        string $campaignId,
        string $adSetName,
        int $dailyBudget = 50000,
        array $targeting = [],
        string $billingEvent = 'LINK_CLICKS',
        string $optimizationGoal = 'LINK_CLICKS'
    ): ?array {
        try {
            $data = [
                'campaign_id' => $campaignId,
                'name' => $adSetName,
                'daily_budget' => $dailyBudget,
                'billing_event' => $billingEvent,
                'optimization_goal' => $optimizationGoal,
            ];

            // Add targeting if provided
            if (!empty($targeting)) {
                $data['targeting'] = json_encode($targeting);
            }

            $response = $this->post("/{$campaignId}/adsets", $data);

            if ($response && isset($response['id'])) {
                Log::info("Created ad set for campaign {$campaignId}", [
                    'customer_id' => $this->customer->id,
                    'adset_id' => $response['id'],
                    'adset_name' => $adSetName,
                ]);
                return $response;
            }

            Log::error("Failed to create ad set", [
                'customer_id' => $this->customer->id,
                'campaign_id' => $campaignId,
                'response' => $response,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Error creating ad set: " . $e->getMessage(), [
                'exception' => $e,
                'campaign_id' => $campaignId,
                'adset_name' => $adSetName,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Update an ad set.
     *
     * @param string $adSetId Ad set ID
     * @param array $updateData Data to update
     * @return bool
     */
    public function updateAdSet(string $adSetId, array $updateData): bool
    {
        try {
            $response = $this->put("/{$adSetId}", $updateData);

            if ($response && isset($response['success']) && $response['success']) {
                Log::info("Updated ad set {$adSetId}", [
                    'customer_id' => $this->customer->id,
                ]);
                return true;
            }

            Log::error("Failed to update ad set", [
                'customer_id' => $this->customer->id,
                'adset_id' => $adSetId,
                'response' => $response,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("Error updating ad set: " . $e->getMessage(), [
                'exception' => $e,
                'adset_id' => $adSetId,
                'customer_id' => $this->customer->id,
            ]);
            return false;
        }
    }

    /**
     * Get ad set performance insights.
     *
     * @param string $adSetId Ad set ID
     * @param string $dateStart Start date (YYYY-MM-DD)
     * @param string $dateEnd End date (YYYY-MM-DD)
     * @return ?array
     */
    public function getAdSetInsights(string $adSetId, string $dateStart, string $dateEnd): ?array
    {
        try {
            $response = $this->get("/{$adSetId}/insights", [
                'time_range' => json_encode(['since' => $dateStart, 'until' => $dateEnd]),
                'fields' => 'adset_id,adset_name,spend,impressions,clicks,ctr,cpc,conversions,cost_per_action_type',
                'time_increment' => 'daily',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved insights for ad set {$adSetId}", [
                    'customer_id' => $this->customer->id,
                    'data_points' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error getting ad set insights: " . $e->getMessage(), [
                'exception' => $e,
                'adset_id' => $adSetId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }
}
