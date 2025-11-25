<?php

namespace App\Services\GoogleAds\VideoServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Ad;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V22\Common\ResponsiveVideoAdInfo;
use Google\Ads\GoogleAds\V22\Common\AdTextAsset;
use Google\Ads\GoogleAds\V22\Common\AdImageAsset;
use Google\Ads\GoogleAds\V22\Common\AdVideoAsset;
use Google\Ads\GoogleAds\V22\Services\AdGroupAdService;
use Google\Ads\GoogleAds\V22\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateResponsiveVideoAd extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a Responsive Video Ad within a Video Ad Group, linking headlines, descriptions, and video assets.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $adGroupResourceName The resource name of the parent Video Ad Group.
     * @param array $adData Ad details including finalUrls, headlines, descriptions, videoAssets, etc.
     * @return string|null The resource name of the created Responsive Video Ad, or null on failure.
     */
    public function __invoke(string $customerId, string $adGroupResourceName, array $adData): ?string
    {
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

        // Prepare video assets
        $videoAssets = [];
        foreach ($adData['videoAssets'] as $videoAssetResourceName) {
            $videoAssets[] = new AdVideoAsset(['asset' => $videoAssetResourceName]);
        }

        // Create ResponsiveVideoAdInfo
        $responsiveVideoAdInfo = new ResponsiveVideoAdInfo([
            'headlines' => $headlines,
            'descriptions' => $descriptions,
            'videos' => $videoAssets,
            'final_urls' => $adData['finalUrls'],
            'long_headlines' => $adData['longHeadlines'] ?? [], // Optional
            'call_to_actions' => $adData['callToActions'] ?? [], // Optional
            'business_name' => $adData['businessName'] ?? null,
        ]);

        // Create Ad
        $ad = new Ad([
            'responsive_video_ad' => $responsiveVideoAdInfo,
        ]);

        // Create AdGroupAd
        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status' => AdGroupAdStatus::ENABLED,
            'ad' => $ad,
        ]);

        // Create AdGroupAdOperation
        $adGroupAdOperation = new AdGroupAdOperation();
        $adGroupAdOperation->create = $adGroupAd;

        try {
            $adGroupAdServiceClient = $this->client->getAdGroupAdServiceClient();
            $response = $adGroupAdServiceClient->mutateAdGroupAds($customerId, [$adGroupAdOperation]);
            $newAdGroupAdResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Responsive Video Ad: " . $newAdGroupAdResourceName);
            return $newAdGroupAdResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Responsive Video Ad for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
