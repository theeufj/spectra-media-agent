<?php

namespace App\Services\GoogleAds\SearchServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V15\Resources\Ad;
use Google\Ads\GoogleAds\V15\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V15\Common\ResponsiveSearchAdInfo;
use Google\Ads\GoogleAds\V15\Common\AdTextAsset;
use Google\Ads\GoogleAds\V15\Services\AdGroupAdService;
use Google\Ads\GoogleAds\V15\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V15\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V15\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateResponsiveSearchAd extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a Responsive Search Ad within a Search Ad Group.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $adGroupResourceName The resource name of the parent Search Ad Group.
     * @param array $adData Ad details including finalUrls, headlines, descriptions, etc.
     * @return string|null The resource name of the created Responsive Search Ad, or null on failure.
     */
    public function __invoke(string $customerId, string $adGroupResourceName, array $adData): ?string
    {
        // Prepare headline text assets (up to 15, minimum 3)
        $headlines = [];
        foreach ($adData['headlines'] as $headlineText) {
            $headlines[] = new AdTextAsset(['text' => substr($headlineText, 0, 30)]); // Max 30 chars
        }

        // Ensure minimum 3 headlines
        while (count($headlines) < 3) {
            $headlines[] = new AdTextAsset(['text' => $adData['headlines'][0] ?? 'Default Headline']);
        }

        // Prepare description text assets (up to 4, minimum 2)
        $descriptions = [];
        foreach ($adData['descriptions'] as $descriptionText) {
            $descriptions[] = new AdTextAsset(['text' => substr($descriptionText, 0, 90)]); // Max 90 chars
        }

        // Ensure minimum 2 descriptions
        while (count($descriptions) < 2) {
            $descriptions[] = new AdTextAsset(['text' => $adData['descriptions'][0] ?? 'Default Description']);
        }

        // Create ResponsiveSearchAdInfo
        $responsiveSearchAdInfo = new ResponsiveSearchAdInfo([
            'headlines' => $headlines,
            'descriptions' => $descriptions,
            'path1' => $adData['path1'] ?? null,
            'path2' => $adData['path2'] ?? null,
        ]);

        // Create Ad
        $ad = new Ad([
            'responsive_search_ad' => $responsiveSearchAdInfo,
            'final_urls' => $adData['finalUrls'],
        ]);

        // Create AdGroupAd
        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'ad' => $ad,
            'status' => AdGroupAdStatus::ENABLED,
        ]);

        $adGroupAdOperation = new AdGroupAdOperation();
        $adGroupAdOperation->setCreate($adGroupAd);

        try {
            $adGroupAdServiceClient = $this->client->getAdGroupAdServiceClient();
            $response = $adGroupAdServiceClient->mutateAdGroupAds($customerId, [$adGroupAdOperation]);
            $newAdGroupAdResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Responsive Search Ad: " . $newAdGroupAdResourceName);
            return $newAdGroupAdResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Responsive Search Ad for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
