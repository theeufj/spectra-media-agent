<?php

namespace App\Services\GoogleAds\DemandGenServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Ad;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V22\Common\DemandGenMultiAssetAdInfo;
use Google\Ads\GoogleAds\V22\Common\AdTextAsset;
use Google\Ads\GoogleAds\V22\Common\AdImageAsset;
use Google\Ads\GoogleAds\V22\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateDemandGenMultiAssetAd extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a Demand Gen Multi-Asset Ad within a Demand Gen Ad Group.
     *
     * This ad format supports images, headlines, descriptions, and optional logos,
     * and runs across YouTube, Gmail, and Discover feeds.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $adGroupResourceName The resource name of the parent ad group.
     * @param array $adData Ad details:
     *   - finalUrls: array of landing page URLs
     *   - headlines: array of headline strings (min 1, max 5, 40 chars)
     *   - descriptions: array of description strings (min 1, max 5, 90 chars)
     *   - businessName: string
     *   - imageAssets: array of image asset resource names (landscape marketing)
     *   - squareImageAssets: array of square image asset resource names (optional)
     *   - logoAssets: array of logo asset resource names (optional)
     *   - callToActionText: string (optional, e.g. 'Learn More')
     * @return string|null The resource name of the created ad, or null on failure.
     */
    public function __invoke(string $customerId, string $adGroupResourceName, array $adData): ?string
    {
        $this->ensureClient();

        // Prepare headline text assets
        $headlines = [];
        foreach ($adData['headlines'] as $headlineText) {
            $headlines[] = new AdTextAsset(['text' => $headlineText]);
        }

        // Prepare description text assets
        $descriptions = [];
        foreach ($adData['descriptions'] as $descriptionText) {
            $descriptions[] = new AdTextAsset(['text' => $descriptionText]);
        }

        // Prepare marketing image assets (landscape)
        $marketingImages = [];
        foreach ($adData['imageAssets'] ?? [] as $imageAssetResourceName) {
            $marketingImages[] = new AdImageAsset(['asset' => $imageAssetResourceName]);
        }

        // Square marketing images
        $squareMarketingImages = [];
        foreach ($adData['squareImageAssets'] ?? $adData['imageAssets'] ?? [] as $imageAssetResourceName) {
            $squareMarketingImages[] = new AdImageAsset(['asset' => $imageAssetResourceName]);
        }

        // Logo images
        $logoImages = [];
        foreach ($adData['logoAssets'] ?? [] as $logoAssetResourceName) {
            $logoImages[] = new AdImageAsset(['asset' => $logoAssetResourceName]);
        }

        // Build DemandGenMultiAssetAdInfo
        $adInfo = new DemandGenMultiAssetAdInfo([
            'marketing_images' => $marketingImages,
            'square_marketing_images' => $squareMarketingImages,
            'logo_images' => $logoImages,
            'headlines' => $headlines,
            'descriptions' => $descriptions,
            'business_name' => $adData['businessName'] ?? '',
            'call_to_action_text' => $adData['callToActionText'] ?? 'Learn more',
        ]);

        // Create Ad
        $ad = new Ad([
            'demand_gen_multi_asset_ad' => $adInfo,
            'final_urls' => $adData['finalUrls'],
        ]);

        // Create AdGroupAd
        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status' => AdGroupAdStatus::ENABLED,
            'ad' => $ad,
        ]);

        $adGroupAdOperation = new AdGroupAdOperation();
        $adGroupAdOperation->setCreate($adGroupAd);

        try {
            $adGroupAdServiceClient = $this->client->getAdGroupAdServiceClient();
            $request = new MutateAdGroupAdsRequest([
                'customer_id' => $customerId,
                'operations' => [$adGroupAdOperation],
            ]);
            $response = $adGroupAdServiceClient->mutateAdGroupAds($request);
            $newAdGroupAdResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Demand Gen Multi-Asset Ad: " . $newAdGroupAdResourceName);
            return $newAdGroupAdResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Demand Gen Multi-Asset Ad for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
