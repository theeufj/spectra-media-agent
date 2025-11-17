<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class InsightService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Get insights for a campaign.
     *
     * @param string $campaignId The Facebook campaign ID
     * @param string $dateStart Start date in format YYYY-MM-DD
     * @param string $dateEnd End date in format YYYY-MM-DD
     * @param array $fields Fields to retrieve (default: standard metrics)
     * @return ?array
     */
    public function getCampaignInsights(
        string $campaignId,
        string $dateStart,
        string $dateEnd,
        array $fields = null
    ): ?array {
        try {
            if ($fields === null) {
                $fields = [
                    'impressions',
                    'clicks',
                    'spend',
                    'actions',
                    'action_values',
                    'reach',
                    'frequency',
                    'cpc',
                    'cpm',
                    'cpa',
                    'date_start',
                    'date_stop',
                ];
            }

            $response = $this->get("/{$campaignId}/insights", [
                'fields' => implode(',', $fields),
                'time_range' => json_encode([
                    'since' => $dateStart,
                    'until' => $dateEnd,
                ]),
                'time_increment' => '1',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved campaign insights for campaign {$campaignId}", [
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

    /**
     * Get insights for an ad set.
     *
     * @param string $adSetId The Facebook ad set ID
     * @param string $dateStart Start date in format YYYY-MM-DD
     * @param string $dateEnd End date in format YYYY-MM-DD
     * @param array $fields Fields to retrieve
     * @return ?array
     */
    public function getAdSetInsights(
        string $adSetId,
        string $dateStart,
        string $dateEnd,
        array $fields = null
    ): ?array {
        try {
            if ($fields === null) {
                $fields = [
                    'impressions',
                    'clicks',
                    'spend',
                    'actions',
                    'reach',
                    'frequency',
                    'cpc',
                    'cpm',
                    'date_start',
                    'date_stop',
                ];
            }

            $response = $this->get("/{$adSetId}/insights", [
                'fields' => implode(',', $fields),
                'time_range' => json_encode([
                    'since' => $dateStart,
                    'until' => $dateEnd,
                ]),
                'time_increment' => '1',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved ad set insights for ad set {$adSetId}", [
                    'customer_id' => $this->customer->id,
                    'data_points' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error getting ad set insights: " . $e->getMessage(), [
                'exception' => $e,
                'ad_set_id' => $adSetId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Get insights for an individual ad.
     *
     * @param string $adId The Facebook ad ID
     * @param string $dateStart Start date in format YYYY-MM-DD
     * @param string $dateEnd End date in format YYYY-MM-DD
     * @param array $fields Fields to retrieve
     * @return ?array
     */
    public function getAdInsights(
        string $adId,
        string $dateStart,
        string $dateEnd,
        array $fields = null
    ): ?array {
        try {
            if ($fields === null) {
                $fields = [
                    'impressions',
                    'clicks',
                    'spend',
                    'actions',
                    'reach',
                    'frequency',
                    'cpc',
                    'cpm',
                    'date_start',
                    'date_stop',
                ];
            }

            $response = $this->get("/{$adId}/insights", [
                'fields' => implode(',', $fields),
                'time_range' => json_encode([
                    'since' => $dateStart,
                    'until' => $dateEnd,
                ]),
                'time_increment' => '1',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved ad insights for ad {$adId}", [
                    'customer_id' => $this->customer->id,
                    'data_points' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error getting ad insights: " . $e->getMessage(), [
                'exception' => $e,
                'ad_id' => $adId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Get account-level insights.
     *
     * @param string $accountId The Facebook ad account ID (without 'act_' prefix)
     * @param string $dateStart Start date in format YYYY-MM-DD
     * @param string $dateEnd End date in format YYYY-MM-DD
     * @param array $fields Fields to retrieve
     * @return ?array
     */
    public function getAccountInsights(
        string $accountId,
        string $dateStart,
        string $dateEnd,
        array $fields = null
    ): ?array {
        try {
            if ($fields === null) {
                $fields = [
                    'impressions',
                    'clicks',
                    'spend',
                    'actions',
                    'reach',
                    'frequency',
                    'cpc',
                    'cpm',
                    'date_start',
                    'date_stop',
                ];
            }

            $response = $this->get("/act_{$accountId}/insights", [
                'fields' => implode(',', $fields),
                'time_range' => json_encode([
                    'since' => $dateStart,
                    'until' => $dateEnd,
                ]),
                'time_increment' => '1',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved account insights for account {$accountId}", [
                    'customer_id' => $this->customer->id,
                    'data_points' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error getting account insights: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Parse an action object from Facebook insights response.
     * Actions are returned as arrays like: [['action_type' => 'purchase', 'value' => '100']...]
     *
     * @param array|null $actions The actions array from Facebook response
     * @param string $actionType The action type to extract (e.g., 'purchase', 'add_to_cart')
     * @return float
     */
    public function parseAction(?array $actions, string $actionType = 'purchase'): float
    {
        if (!$actions || !is_array($actions)) {
            return 0;
        }

        foreach ($actions as $action) {
            if (isset($action['action_type']) && $action['action_type'] === $actionType) {
                return (float) ($action['value'] ?? 0);
            }
        }

        return 0;
    }
}
