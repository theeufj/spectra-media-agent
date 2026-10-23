<?php

namespace App\Services\GoogleAds\VideoServices;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Common\AdImageAsset;
use Google\Ads\GoogleAds\V26\Common\AdTextAsset;
use Google\Ads\GoogleAds\V26\Common\AdVideoAsset;
use Google\Ads\GoogleAds\V26\Common\VideoResponsiveAdInfo;
use Google\Ads\GoogleAds\V26\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V26\Resources\Ad;
use Google\Ads\GoogleAds\V26\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V26\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V26\Services\MutateAdGroupAdsRequest;

class CreateResponsiveVideoAd extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a Responsive Video Ad within a Video Ad Group, linking headlines, descriptions, and video assets.
     *
     * @param  string  $customerId  The Google Ads customer ID.
     * @param  string  $adGroupResourceName  The resource name of the parent Video Ad Group.
     * @param  array  $adData  Ad details including finalUrls, headlines, descriptions, videoAssets, etc.
     * @return string|null The resource name of the created Responsive Video Ad, or null on failure.
     */
    public function __invoke(string $customerId, string $adGroupResourceName, array $adData): ?string
    {
        if (trim($adData['businessName'] ?? '') === '' || empty($adData['logoAssets'])) {
            throw new \InvalidArgumentException('A responsive video ad requires a business name and square logo assets.');
        }

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

        $logoAssets = array_map(static fn (string $asset): AdImageAsset => new AdImageAsset(['asset' => $asset]), $adData['logoAssets']);
        $longHeadlines = array_map(static fn (string $text): AdTextAsset => new AdTextAsset(['text' => $text]), $adData['longHeadlines'] ?? []);
        $callToActions = array_map(static fn (string $text): AdTextAsset => new AdTextAsset(['text' => $text]), $adData['callToActions'] ?? []);

        $responsiveVideoAdInfo = new VideoResponsiveAdInfo([
            'headlines' => $headlines,
            'descriptions' => $descriptions,
            'videos' => $videoAssets,
            'long_headlines' => $longHeadlines,
            'call_to_actions' => $callToActions,
            'business_name' => new AdTextAsset(['text' => $adData['businessName']]),
            'logo_images' => $logoAssets,
        ]);

        // Create Ad
        $ad = new Ad([
            'video_responsive_ad' => $responsiveVideoAdInfo,
            'final_urls' => $adData['finalUrls'],
        ]);

        // Create AdGroupAd
        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status' => AdGroupAdStatus::ENABLED,
            'ad' => $ad,
        ]);

        // Create AdGroupAdOperation
        $adGroupAdOperation = new AdGroupAdOperation;
        $adGroupAdOperation->setCreate($adGroupAd);

        try {
            $adGroupAdServiceClient = $this->client->getAdGroupAdServiceClient();
            $request = new MutateAdGroupAdsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [$adGroupAdOperation],
            ]);
            $response = $adGroupAdServiceClient->mutateAdGroupAds($request);
            $newAdGroupAdResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo('Successfully created Responsive Video Ad: '.$newAdGroupAdResourceName);

            return $newAdGroupAdResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Responsive Video Ad for customer $customerId: ".$e->getMessage(), $e);

            return null;
        }
    }
}
