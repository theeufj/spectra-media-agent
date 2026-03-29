<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookAdsService
{
    protected $baseGraphUrl = 'https://graph.facebook.com/v19.0';

    /**
     * The access token of the user/organization on whose behalf we are acting.
     * This must be obtained via the OAuth 2.0 flow.
     */
    protected $accessToken;

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Creates a new ad campaign on the Facebook Ads platform.
     *
     * @param Campaign $campaign The local campaign model.
     * @param string $adAccountId The Facebook Ad Account ID.
     * @return string|null The ID of the newly created Facebook campaign, or null on failure.
     */
    public function createCampaign(Campaign $campaign, string $adAccountId): ?string
    {
        Log::info("Creating Facebook Ad Campaign for local Campaign ID: {$campaign->id}");

        try {
            $response = Http::post("{$this->baseGraphUrl}/{$adAccountId}/campaigns", [
                'name' => $campaign->name,
                'objective' => 'OUTCOME_LEADS',
                'status' => 'PAUSED',
                'special_ad_categories' => [],
                'access_token' => $this->accessToken,
            ]);

            if (!$response->successful()) {
                Log::error('FacebookAdsService: Failed to create campaign', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return null;
            }

            return $response->json()['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('FacebookAdsService: Exception creating campaign: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Creates an ad set within a Facebook campaign.
     *
     * @param string $campaignId The Facebook Campaign ID.
     * @return string|null The ID of the newly created ad set.
     */
    public function createAdSet(string $campaignId, array $adSetData = []): ?string
    {
        Log::info("Creating Facebook Ad Set for Facebook Campaign ID: {$campaignId}");

        try {
            $response = Http::post("{$this->baseGraphUrl}/{$campaignId}/adsets", array_merge([
                'campaign_id' => $campaignId,
                'status' => 'PAUSED',
                'access_token' => $this->accessToken,
            ], $adSetData));

            if (!$response->successful()) {
                Log::error('FacebookAdsService: Failed to create ad set', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return null;
            }

            return $response->json()['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('FacebookAdsService: Exception creating ad set: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Creates an ad creative (the visual part of the ad).
     *
     * @param string $adAccountId The Facebook Ad Account ID.
     * @return string|null The ID of the newly created ad creative.
     */
    public function createAdCreative(string $adAccountId, array $creativeData = []): ?string
    {
        Log::info("Creating Facebook Ad Creative for Ad Account ID: {$adAccountId}");

        try {
            $response = Http::post("{$this->baseGraphUrl}/{$adAccountId}/adcreatives", array_merge([
                'access_token' => $this->accessToken,
            ], $creativeData));

            if (!$response->successful()) {
                Log::error('FacebookAdsService: Failed to create ad creative', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return null;
            }

            return $response->json()['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('FacebookAdsService: Exception creating ad creative: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Creates the final ad, linking the campaign, ad set, and creative.
     *
     * @param string $adSetId The Facebook Ad Set ID.
     * @param string $creativeId The Facebook Ad Creative ID.
     * @return string|null The ID of the newly created ad.
     */
    public function createAd(string $adSetId, string $creativeId): ?string
    {
        Log::info("Creating Facebook Ad for Ad Set ID: {$adSetId}");

        try {
            $response = Http::post("{$this->baseGraphUrl}/{$adSetId}/ads", [
                'adset_id' => $adSetId,
                'creative' => ['creative_id' => $creativeId],
                'status' => 'PAUSED',
                'access_token' => $this->accessToken,
            ]);

            if (!$response->successful()) {
                Log::error('FacebookAdsService: Failed to create ad', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return null;
            }

            return $response->json()['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('FacebookAdsService: Exception creating ad: ' . $e->getMessage());
            return null;
        }
    }
}
