<?php

namespace App\Services;

use App\Models\Campaign;
use App\Services\GoogleAdsSettings\BiddingStrategy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\PendingRequest;

class GoogleAdsService
{
    protected $baseApiUrl = 'https://googleads.googleapis.com/v16';
    protected PendingRequest $httpClient;

    public function __construct(
        string $accessToken,
        string $developerToken,
        string $customerId,
        ?string $loginCustomerId = null
    ) {
        $headers = [
            'Authorization' => "Bearer {$accessToken}",
            'developer-token' => $developerToken,
        ];

        if ($loginCustomerId) {
            $headers['login-customer-id'] = $loginCustomerId;
        }

        $this->httpClient = Http::withHeaders($headers)->acceptJson();
    }

    /**
     * Creates a new Campaign Budget. Budgets are required before creating a campaign.
     */
    public function createCampaignBudget(string $customerId, string $budgetName, int $dailyBudgetMicros = 5000000): ?string
    {
        $url = "{$this->baseApiUrl}/customers/{$customerId}/campaignBudgets:mutate";

        $response = $this->httpClient->post($url, [
            'operations' => [
                'create' => [
                    'name' => $budgetName,
                    'amountMicros' => $dailyBudgetMicros,
                    'deliveryMethod' => 'STANDARD',
                ]
            ]
        ]);

        if ($response->failed()) {
            Log::error('Google Ads API Error: Failed to create campaign budget.', ['response' => $response->json()]);
            return null;
        }

        return $response->json()['results'][0]['resourceName'] ?? null;
    }

    /**
     * Creates a new Search Campaign.
     */
    public function createSearchCampaign(string $customerId, Campaign $campaign, string $budgetResourceName, BiddingStrategy $biddingStrategy): ?string
    {
        $url = "{$this->baseApiUrl}/customers/{$customerId}/campaigns:mutate";

        $response = $this->httpClient->post($url, [
            'operations' => [
                'create' => array_merge(
                    [
                        'name' => $campaign->name . ' (Search)',
                        'status' => 'PAUSED',
                        'advertisingChannelType' => 'SEARCH',
                        'campaignBudget' => $budgetResourceName,
                        'networkSettings' => [
                            'targetGoogleSearch' => true,
                            'targetSearchNetwork' => true,
                            'targetContentNetwork' => false,
                            'targetPartnerSearchNetwork' => false,
                        ],
                    ],
                    $biddingStrategy->getConfiguration()
                )
            ]
        ]);

        if ($response->failed()) {
            Log::error('Google Ads API Error: Failed to create search campaign.', ['response' => $response->json()]);
            return null;
        }

        return $response->json()['results'][0]['resourceName'] ?? null;
    }

    /**
     * Creates a new Display Campaign.
     */
    public function createDisplayCampaign(string $customerId, Campaign $campaign, string $budgetResourceName, BiddingStrategy $biddingStrategy): ?string
    {
        $url = "{$this->baseApiUrl}/customers/{$customerId}/campaigns:mutate";

        $response = $this->httpClient->post($url, [
            'operations' => [
                'create' => array_merge(
                    [
                        'name' => $campaign->name . ' (Display)',
                        'status' => 'PAUSED',
                        'advertisingChannelType' => 'DISPLAY',
                        'campaignBudget' => $budgetResourceName,
                    ],
                    $biddingStrategy->getConfiguration()
                )
            ]
        ]);

        if ($response->failed()) {
            Log::error('Google Ads API Error: Failed to create display campaign.', ['response' => $response->json()]);
            return null;
        }

        return $response->json()['results'][0]['resourceName'] ?? null;
    }

    /**
     * Creates an Ad Group within a campaign.
     */
    public function createAdGroup(string $customerId, string $campaignResourceName, string $adGroupName): ?string
    {
        $url = "{$this->baseApiUrl}/customers/{$customerId}/adGroups:mutate";

        $response = $this->httpClient->post($url, [
            'operations' => [
                'create' => [
                    'name' => $adGroupName,
                    'campaign' => $campaignResourceName,
                    'status' => 'PAUSED',
                    // Add bidding and targeting settings here
                ]
            ]
        ]);

        if ($response->failed()) {
            Log::error('Google Ads API Error: Failed to create ad group.', ['response' => $response->json()]);
            return null;
        }

        return $response->json()['results'][0]['resourceName'] ?? null;
    }

    /**
     * Creates a Responsive Search Ad.
     */
    public function createResponsiveSearchAd(string $customerId, string $adGroupResourceName, array $headlines, array $descriptions, string $landingPageUrl): ?string
    {
        $url = "{$this->baseApiUrl}/customers/{$customerId}/ads:mutate";

        $headlineAssets = array_map(fn($text) => ['text' => $text], $headlines);
        $descriptionAssets = array_map(fn($text) => ['text' => $text], $descriptions);

        $response = $this->httpClient->post($url, [
            'operations' => [
                'create' => [
                    'ad' => [
                        'responsiveSearchAd' => [
                            'headlines' => $headlineAssets,
                            'descriptions' => $descriptionAssets,
                        ],
                        'finalUrls' => [$landingPageUrl],
                    ],
                    'adGroup' => $adGroupResourceName,
                    'status' => 'PAUSED',
                ]
            ]
        ]);

        if ($response->failed()) {
            Log::error('Google Ads API Error: Failed to create responsive search ad.', ['response' => $response->json()]);
            return null;
        }

        return $response->json()['results'][0]['resourceName'] ?? null;
    }

    /**
     * Uploads an image asset to be used in Display Ads.
     */
    public function uploadImageAsset(string $customerId, string $imageDataBase64, string $assetName): ?string
    {
        $url = "{$this->baseApiUrl}/customers/{$customerId}/assets:mutate";

        $response = $this->httpClient->post($url, [
            'operations' => [
                'create' => [
                    'name' => $assetName,
                    'type' => 'IMAGE',
                    'imageAsset' => [
                        'data' => $imageDataBase64,
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Google Ads API Error: Failed to upload image asset.', ['response' => $response->json()]);
            return null;
        }

        return $response->json()['results'][0]['resourceName'] ?? null;
    }
}
