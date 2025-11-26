<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Common\ImageAsset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class CreateImageAsset extends BaseGoogleAdsService
{
    /**
     * Create an Image Asset from a URL or file path.
     *
     * @param string $customerId
     * @param string $imageUrl URL or local path to the image
     * @param string $name Name for the asset
     * @return string|null Resource name of the created Asset
     */
    public function __invoke(string $customerId, string $imageUrl, string $name): ?string
    {
        $this->ensureClient();

        // Get image content
        $imageContent = file_get_contents($imageUrl);
        if ($imageContent === false) {
            $this->logError("Failed to read image content from: $imageUrl");
            return null;
        }

        $imageAsset = new ImageAsset([
            'data' => $imageContent,
        ]);

        $asset = new Asset([
            'name' => $name,
            'type' => AssetType::IMAGE,
            'image_asset' => $imageAsset,
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
            $this->logInfo("Created Image Asset: $assetResourceName");

            return $assetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create Image Asset: " . $e->getMessage());
            return null;
        }
    }
}
