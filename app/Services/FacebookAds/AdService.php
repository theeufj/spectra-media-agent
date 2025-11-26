<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Log;
use App\Models\Customer;
use App\Services\CampaignStatusHelper;

class AdService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * List all ads for an ad set.
     *
     * @param string $adSetId Ad set ID
     * @return ?array
     */
    public function listAds(string $adSetId): ?array
    {
        try {
            $response = $this->get("/{$adSetId}/ads", [
                'fields' => 'id,name,status,creative,adset_id,created_time,updated_time',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved ads for ad set {$adSetId}", [
                    'customer_id' => $this->customer->id,
                    'ad_count' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error listing ads: " . $e->getMessage(), [
                'exception' => $e,
                'adset_id' => $adSetId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Create a new ad.
     *
     * @param string $adSetId Ad set ID
     * @param string $adName Ad name
     * @param string $creativeId Creative ID
     * @param string|null $status Ad status ('ACTIVE', 'PAUSED'). If null, uses config.
     * @return ?array
     */
    public function createAd(
        string $adSetId,
        string $adName,
        string $creativeId,
        ?string $status = null
    ): ?array {
        // Use CampaignStatusHelper to determine the appropriate status
        $finalStatus = CampaignStatusHelper::getFacebookAdsStatus($status);
        
        try {
            $response = $this->post("/{$adSetId}/ads", [
                'name' => $adName,
                'adset_id' => $adSetId,
                'creative' => ['creative_id' => $creativeId],
                'status' => $finalStatus,
            ]);

            if ($response && isset($response['id'])) {
                Log::info("Created ad for ad set {$adSetId}", [
                    'customer_id' => $this->customer->id,
                    'ad_id' => $response['id'],
                    'ad_name' => $adName,
                ]);
                return $response;
            }

            Log::error("Failed to create ad", [
                'customer_id' => $this->customer->id,
                'adset_id' => $adSetId,
                'response' => $response,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Error creating ad: " . $e->getMessage(), [
                'exception' => $e,
                'adset_id' => $adSetId,
                'ad_name' => $adName,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Update an ad.
     *
     * @param string $adId Ad ID
     * @param array $updateData Data to update
     * @return bool
     */
    public function updateAd(string $adId, array $updateData): bool
    {
        try {
            $response = $this->put("/{$adId}", $updateData);

            if ($response && isset($response['success']) && $response['success']) {
                Log::info("Updated ad {$adId}", [
                    'customer_id' => $this->customer->id,
                ]);
                return true;
            }

            Log::error("Failed to update ad", [
                'customer_id' => $this->customer->id,
                'ad_id' => $adId,
                'response' => $response,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("Error updating ad: " . $e->getMessage(), [
                'exception' => $e,
                'ad_id' => $adId,
                'customer_id' => $this->customer->id,
            ]);
            return false;
        }
    }

    /**
     * Get ad performance insights.
     *
     * @param string $adId Ad ID
     * @param string $dateStart Start date (YYYY-MM-DD)
     * @param string $dateEnd End date (YYYY-MM-DD)
     * @return ?array
     */
    public function getAdInsights(string $adId, string $dateStart, string $dateEnd): ?array
    {
        try {
            $response = $this->get("/{$adId}/insights", [
                'time_range' => json_encode(['since' => $dateStart, 'until' => $dateEnd]),
                'fields' => 'ad_id,ad_name,spend,impressions,clicks,ctr,cpc,conversions,cost_per_action_type,frequency',
                'time_increment' => 'daily',
            ]);

            if ($response && isset($response['data'])) {
                Log::info("Retrieved insights for ad {$adId}", [
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
     * Pause an ad.
     *
     * @param string $adId Ad ID
     * @return bool
     */
    public function pauseAd(string $adId): bool
    {
        return $this->updateAd($adId, ['status' => 'PAUSED']);
    }

    /**
     * Resume an ad.
     *
     * @param string $adId Ad ID
     * @return bool
     */
    public function resumeAd(string $adId): bool
    {
        return $this->updateAd($adId, ['status' => 'ACTIVE']);
    }
}
