<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\SitelinkAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class CreateSitelinkAsset extends BaseGoogleAdsService
{
    /**
     * Create a Sitelink Asset.
     *
     * @param string $customerId
     * @param string $linkText The visible text for the sitelink (max 25 chars)
     * @param string $description1 First line of description (max 35 chars)
     * @param string $description2 Second line of description (max 35 chars)
     * @param string $finalUrl The landing page URL
     * @return string|null Resource name of the created Asset
     */
    public function __invoke(string $customerId, string $linkText, string $description1, string $description2, string $finalUrl): ?string
    {
        $this->ensureClient();

        $sitelinkAsset = new SitelinkAsset([
            'link_text' => $linkText,
            'description1' => $description1,
            'description2' => $description2,
        ]);

        $asset = new Asset([
            'name' => 'Sitelink: ' . $linkText . ' - ' . uniqid(),
            'type' => AssetType::SITELINK,
            'sitelink_asset' => $sitelinkAsset,
            'final_urls' => [$finalUrl],
        ]);

        $assetOperation = new AssetOperation();
        $assetOperation->setCreate($asset);

        try {
            $assetServiceClient = $this->client->getAssetServiceClient();
            $response = $assetServiceClient->mutateAssets(new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$assetOperation],
            ]));

            $assetResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Sitelink Asset: $assetResourceName");

            return $assetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create Sitelink Asset: " . $e->getMessage());
            return null;
        }
    }
}
