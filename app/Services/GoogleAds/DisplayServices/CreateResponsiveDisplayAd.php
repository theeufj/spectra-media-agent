<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V15\Resources\Ad;
use Google\Ads\GoogleAds\V15\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V15\Common\ResponsiveDisplayAdInfo;
use Google\Ads\GoogleAds\V15\Common\AdTextAsset;
use Google\Ads\GoogleAds\V15\Common\AdImageAsset;
use Google\Ads\GoogleAds\V15\Services\AdGroupAdService;
use Google\Ads\GoogleAds\V15\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V15\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V15\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateResponsiveDisplayAd extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a Responsive Display Ad within a Display Ad Group, linking headlines, descriptions, and image assets.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $adGroupResourceName The resource name of the parent Display Ad Group.
     * @param array $adData Ad details including finalUrls, headlines, descriptions, imageAssets, etc.
     * @return string|null The resource name of the created Responsive Display Ad, or null on failure.
     */
    public function __invoke(string $customerId, string $adGroupResourceName, array $adData): ?string
    {
        // Prepare headline text assets
        $headlines = [];
        foreach ($adData['headlines'] as $headlineText) {
            $headlines[] = new AdTextAsset(['text' => $headlineText]);
        }

        // Prepare long headline text assets
        $longHeadlines = [];
        foreach ($adData['longHeadlines'] as $longHeadlineText) {
            $longHeadlines[] = new AdTextAsset(['text' => $longHeadlineText]);
        }

        // Prepare description text assets
        $descriptions = [];
        foreach ($adData['descriptions'] as $descriptionText) {
            $descriptions[] = new AdTextAsset(['text' => $descriptionText]);
        }

        // Prepare image assets
        $imageAssets = [];
        foreach ($adData['imageAssets'] as $imageAssetResourceName) {
            $imageAssets[] = new AdImageAsset(['asset' => $imageAssetResourceName]);
        }

        // Prepare logo assets
        $logoAssets = [];
        if (isset($adData['logoAssets'])) {
            foreach ($adData['logoAssets'] as $logoAssetResourceName) {
                $logoAssets[] = new AdImageAsset(['asset' => $logoAssetResourceName]);
            }
        }

        // Create ResponsiveDisplayAdInfo
        $responsiveDisplayAdInfo = new ResponsiveDisplayAdInfo([
            'headlines' => $headlines,
            'long_headlines' => $longHeadlines,
            'descriptions' => $descriptions,
            'marketing_images' => $imageAssets,
            'square_marketing_images' => $imageAssets, // Assuming square images are also provided as marketing images
            'logo_images' => $logoAssets,
            'square_logo_images' => $logoAssets, // Assuming square logos are also provided as logo images
            'final_urls' => $adData['finalUrls'],
            'business_name' => $adData['businessName'] ?? null,
        ]);

        // Create Ad
        $ad = new Ad([
            'responsive_display_ad' => $responsiveDisplayAdInfo,
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
            $this->logInfo("Successfully created Responsive Display Ad: " . $newAdGroupAdResourceName);
            return $newAdGroupAdResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Responsive Display Ad for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
