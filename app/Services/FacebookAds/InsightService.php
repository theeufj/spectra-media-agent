<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class InsightService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function reportingAdSets(string $campaignId): ?array
    {
        $rows = $this->getAllPages("/{$campaignId}/adsets", ['fields' => 'id', 'limit' => 100], complete: $complete);

        return $complete ? $rows : null;
    }

    public function attributionAdSet(string $id): ?array
    {
        return $this->get("/{$id}", ['fields' => 'id,account_id,campaign_id,promoted_object,updated_time']);
    }

    /** A partial page cannot support a treatment comparison. */
    public function treatmentInsights(string $adset, string $from, string $to): ?array
    {
        $rows = $this->getAllPages("/{$adset}/insights", [
            'fields' => 'account_id,account_currency,campaign_id,adset_id,ad_id,ad_name,impressions,inline_link_clicks,spend,actions,date_start,date_stop',
            'level' => 'ad', 'time_range' => json_encode(['since' => $from, 'until' => $to], JSON_THROW_ON_ERROR),
            'limit' => 100,
        ], complete: $complete);

        return $complete ? $rows : null;
    }

    /**
     * Get insights for a campaign.
     *
     * @param  string  $campaignId  The Facebook campaign ID
     * @param  string  $dateStart  Start date in format YYYY-MM-DD
     * @param  string  $dateEnd  End date in format YYYY-MM-DD
     * @param  array  $fields  Fields to retrieve (default: standard metrics)
     */
    public function getCampaignInsights(
        string $campaignId,
        string $dateStart,
        string $dateEnd,
        ?array $fields = null,
        bool $daily = true,
    ): ?array {
        try {
            if ($fields === null) {
                $fields = [
                    'account_id',
                    'account_currency',
                    'campaign_id',
                    'impressions',
                    'inline_link_clicks',
                    'clicks',
                    'spend',
                    'actions',
                    'action_values',
                    'reach',
                    'frequency',
                    'cpc',
                    'cpm',
                    'date_start',
                    'date_stop',
                ];
                // NOTE: 'cpa' is not a valid Facebook Insights API field — calculate
                // client-side as spend / conversions after fetching.
            }

            $response = $this->get("/{$campaignId}/insights", [
                'fields' => implode(',', $fields),
                'time_range' => json_encode([
                    'since' => $dateStart,
                    'until' => $dateEnd,
                ]),
                'time_increment' => $daily ? '1' : 'all_days',
                'limit' => 100,
            ]);

            if (is_array($response['data'] ?? null) && empty($response['paging']['next'])) {
                Log::info("Retrieved campaign insights for campaign {$campaignId}", [
                    'customer_id' => $this->customer->id,
                    'data_points' => count($response['data']),
                ]);

                return $response['data'];
            }

            Log::warning('Empty or invalid data returned.', [
                'customer_id' => $this->customer->id,
                'response' => $response ?? null,
            ]);

            return null;
        } catch (\Throwable $e) {
            report($e);
            Log::error('Error getting campaign insights: '.$e->getMessage(), [
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
     * @param  string  $adSetId  The Facebook ad set ID
     * @param  string  $dateStart  Start date in format YYYY-MM-DD
     * @param  string  $dateEnd  End date in format YYYY-MM-DD
     * @param  array  $fields  Fields to retrieve
     */
    public function getAdSetInsights(
        string $adSetId,
        string $dateStart,
        string $dateEnd,
        ?array $fields = null
    ): ?array {
        try {
            if ($fields === null) {
                $fields = [
                    'account_id',
                    'account_currency',
                    'campaign_id',
                    'adset_id',
                    'impressions',
                    'inline_link_clicks',
                    'clicks',
                    'spend',
                    'actions',
                    'action_values',
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
                'limit' => 100,
            ]);

            if (is_array($response['data'] ?? null) && empty($response['paging']['next'])) {
                Log::info("Retrieved ad set insights for ad set {$adSetId}", [
                    'customer_id' => $this->customer->id,
                    'data_points' => count($response['data']),
                ]);

                return $response['data'];
            }

            Log::warning('Empty or invalid data returned.', [
                'customer_id' => $this->customer->id,
                'response' => $response ?? null,
            ]);

            return null;
        } catch (\Throwable $e) {
            report($e);
            Log::error('Error getting ad set insights: '.$e->getMessage(), [
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
     * @param  string  $adId  The Facebook ad ID
     * @param  string  $dateStart  Start date in format YYYY-MM-DD
     * @param  string  $dateEnd  End date in format YYYY-MM-DD
     * @param  array  $fields  Fields to retrieve
     */
    public function getAdInsights(
        string $adId,
        string $dateStart,
        string $dateEnd,
        ?array $fields = null
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

            Log::warning('Empty or invalid data returned.', [
                'customer_id' => $this->customer->id,
                'response' => $response ?? null,
            ]);

            return [];
        } catch (\Throwable $e) {
            report($e);
            Log::error('Error getting ad insights: '.$e->getMessage(), [
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
     * @param  string  $accountId  The Facebook ad account ID (without 'act_' prefix)
     * @param  string  $dateStart  Start date in format YYYY-MM-DD
     * @param  string  $dateEnd  End date in format YYYY-MM-DD
     * @param  array  $fields  Fields to retrieve
     */
    public function getAccountInsights(
        string $accountId,
        string $dateStart,
        string $dateEnd,
        ?array $fields = null
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

            Log::warning('Empty or invalid data returned.', [
                'customer_id' => $this->customer->id,
                'response' => $response ?? null,
            ]);

            return [];
        } catch (\Throwable $e) {
            report($e);
            Log::error('Error getting account insights: '.$e->getMessage(), [
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
     * @param  array|null  $actions  The actions array from Facebook response
     * @param  string  $actionType  The action type to extract (e.g., 'purchase', 'add_to_cart')
     */
    public function parseAction(?array $actions, string $actionType = 'purchase'): float
    {
        if (! $actions || ! is_array($actions)) {
            return 0;
        }

        foreach ($actions as $action) {
            if (isset($action['action_type']) && $action['action_type'] === $actionType) {
                return (float) ($action['value'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Get account-level insights with a specific level (campaign, adset, ad).
     *
     * @param  string  $accountId  Account ID (with act_ prefix)
     * @param  string  $dateStart  Start date YYYY-MM-DD
     * @param  string  $dateEnd  End date YYYY-MM-DD
     * @param  string  $level  Breakdown level: 'account', 'campaign', 'adset', 'ad'
     * @param  array|null  $fields  Fields to retrieve
     * @param  int  $limit  Max rows
     */
    public function getAccountInsightsByLevel(
        string $accountId,
        string $dateStart,
        string $dateEnd,
        string $level = 'account',
        ?array $fields = null,
        int $limit = 100,
        ?string $campaignId = null,
    ): array {
        try {
            $params = [
                'fields' => implode(',', $fields ?? ['impressions', 'clicks', 'spend', 'actions', 'reach', 'frequency', 'cpc', 'cpm']),
                'time_range' => json_encode(['since' => $dateStart, 'until' => $dateEnd]),
                'limit' => $limit,
            ];

            if ($level !== 'account') {
                $params['level'] = $level;
            }

            if ($campaignId !== null) {
                $params['filtering'] = json_encode([['field' => 'campaign.id', 'operator' => 'IN', 'value' => [$campaignId]]], JSON_THROW_ON_ERROR);
            }
            $response = $this->get("/{$accountId}/insights", $params);

            return $response['data'] ?? [];
        } catch (\Throwable $e) {
            report($e);
            Log::error('Error getting account insights by level: '.$e->getMessage(), [
                'account_id' => $accountId,
                'level' => $level,
            ]);

            return [];
        }
    }
}
