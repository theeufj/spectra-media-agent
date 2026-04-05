<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Ad;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V22\Common\ResponsiveDisplayAdInfo;
use Google\Ads\GoogleAds\V22\Common\AdTextAsset;
use Google\Ads\GoogleAds\V22\Common\AdImageAsset;
use Google\Ads\GoogleAds\V22\Services\AdGroupAdService;
use Google\Ads\GoogleAds\V22\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
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

        // Prepare landscape marketing image assets (1.91:1 aspect ratio)
        $marketingImages = [];
        foreach ($adData['imageAssets'] as $imageAssetResourceName) {
            $marketingImages[] = new AdImageAsset(['asset' => $imageAssetResourceName]);
        }

        // Prepare square marketing image assets (1:1 aspect ratio)
        $squareMarketingImages = [];
        foreach ($adData['squareImageAssets'] ?? $adData['imageAssets'] as $imageAssetResourceName) {
            $squareMarketingImages[] = new AdImageAsset(['asset' => $imageAssetResourceName]);
        }

        // Prepare logo assets: logo_images = 4:1 landscape logos, square_logo_images = 1:1 square logos
        $logoAssets = [];
        if (isset($adData['logoAssets'])) {
            foreach ($adData['logoAssets'] as $logoAssetResourceName) {
                $logoAssets[] = new AdImageAsset(['asset' => $logoAssetResourceName]);
            }
        }
        $squareLogoAssets = [];
        if (isset($adData['squareLogoAssets'])) {
            foreach ($adData['squareLogoAssets'] as $logoAssetResourceName) {
                $squareLogoAssets[] = new AdImageAsset(['asset' => $logoAssetResourceName]);
            }
        }

        // Create ResponsiveDisplayAdInfo
        // long_headline is singular (one AdTextAsset), not an array
        $longHeadline = !empty($longHeadlines) ? $longHeadlines[0] : new AdTextAsset(['text' => $adData['headlines'][0] ?? 'Learn More']);

        $rdaFields = [
            'headlines' => $headlines,
            'long_headline' => $longHeadline,
            'descriptions' => $descriptions,
            'marketing_images' => $marketingImages,
            'square_marketing_images' => $squareMarketingImages,
            'business_name' => $adData['businessName'] ?? null,
        ];
        if (!empty($logoAssets)) {
            $rdaFields['logo_images'] = $logoAssets; // 4:1 landscape logos
        }
        if (!empty($squareLogoAssets)) {
            $rdaFields['square_logo_images'] = $squareLogoAssets; // 1:1 square logos
        }

        $responsiveDisplayAdInfo = new ResponsiveDisplayAdInfo($rdaFields);

        // Create Ad (final_urls belongs on the Ad object, not ResponsiveDisplayAdInfo)
        $ad = new Ad([
            'responsive_display_ad' => $responsiveDisplayAdInfo,
            'final_urls' => $adData['finalUrls'],
        ]);

        // Create AdGroupAd
        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status' => AdGroupAdStatus::ENABLED,
            'ad' => $ad,
        ]);

        // Create AdGroupAdOperation
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
            $this->logInfo("Successfully created Responsive Display Ad: " . $newAdGroupAdResourceName);
            return $newAdGroupAdResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Responsive Display Ad for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
