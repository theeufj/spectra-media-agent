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
     * List ad sets for an ad account (account-level).
     *
     * @param string $accountId Account ID (with act_ prefix)
     * @param array $filters Optional API filtering
     * @param int $limit Max results
     * @return array
     */
    public function listAdSetsByAccount(string $accountId, array $filters = [], int $limit = 100): array
    {
        try {
            $params = [
                'fields' => 'id,name,status,targeting,effective_status,daily_budget,lifetime_budget,bid_strategy,optimization_goal',
                'limit' => $limit,
            ];

            if (!empty($filters)) {
                $params['filtering'] = json_encode($filters);
            }

            $response = $this->get("/{$accountId}/adsets", $params);

            return $response['data'] ?? [];
        } catch (\Exception $e) {
            Log::error("Error listing ad sets by account: " . $e->getMessage(), [
                'account_id' => $accountId,
            ]);
            return [];
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
        string $accountId,
        string $campaignId,
        string $adSetName,
        array $targeting = [],
        string $optimizationGoal = 'LINK_CLICKS',
        string $status = 'PAUSED',
        ?array $promotedObject = null
    ): ?array {
        // billing_event for standard traffic/awareness campaigns is always IMPRESSIONS.
        // The old default of LINK_CLICKS as billing_event causes API errors with OUTCOME_* objectives.
        $billingEvent = 'IMPRESSIONS';

        try {
            $data = [
                'campaign_id'      => $campaignId,
                'name'             => $adSetName,
                // No daily_budget here — campaign uses CBO (Campaign Budget Optimisation);
                // the budget set on the campaign flows down automatically.
                'billing_event'    => $billingEvent,
                'optimization_goal' => $optimizationGoal,
                'status'           => $status,
            ];

            if ($promotedObject !== null) {
                $data['promoted_object'] = json_encode($promotedObject);
            }

            // Add targeting if provided
            if (!empty($targeting)) {
                // Meta requires advantage_audience flag in targeting_automation
                if (!isset($targeting['targeting_automation'])) {
                    $targeting['targeting_automation'] = ['advantage_audience' => 1];
                }
                // Facebook rejects age_max < 65 when Advantage+ audience is active (error 1870189).
                // Clamp to the minimum allowed value; age_min is unaffected.
                if (isset($targeting['age_max']) && $targeting['age_max'] < 65) {
                    $targeting['age_max'] = 65;
                }
                $data['targeting'] = json_encode($targeting);
            } else {
                // Even with no targeting, the flag is required
                $data['targeting'] = json_encode([
                    'targeting_automation' => ['advantage_audience' => 1],
                ]);
            }

            // Correct endpoint: ad sets are created under the ad account, not the campaign.
            // campaign_id is passed in the POST body above.
            $response = $this->post("/act_{$accountId}/adsets", $data);

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
