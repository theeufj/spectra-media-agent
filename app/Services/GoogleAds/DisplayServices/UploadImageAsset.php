<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V15\Resources\Asset;
use Google\Ads\GoogleAds\V15\Common\ImageAsset;
use Google\Ads\GoogleAds\V15\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V15\Services\AssetService;
use Google\Ads\GoogleAds\V15\Services\AssetOperation;
use Google\Ads\GoogleAds\V15\Errors\GoogleAdsException;
use App\Models\Customer;

class UploadImageAsset extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Uploads an image file to be used as an asset in Responsive Display Ads.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $imageFilePath The absolute path to the image file.
     * @param string $imageFileName The name of the image file (e.g., 'my_image.png').
     * @return string|null The resource name of the uploaded image asset, or null on failure.
     */
    public function __invoke(string $customerId, string $imageFilePath, string $imageFileName): ?string
    {
        // Read image data and base64 encode it
        $imageData = @file_get_contents($imageFilePath);
        if ($imageData === false) {
            $this->logError("Could not read image file from path: {$imageFilePath}");
            return null;
        }
        $base64ImageData = base64_encode($imageData);

        // Create ImageAsset
        $imageAsset = new ImageAsset(['data' => $base64ImageData]);

        // Create Asset
        $asset = new Asset([
            'name' => $imageFileName,
            'type' => AssetType::IMAGE,
            'image_asset' => $imageAsset,
        ]);

        // Create AssetOperation
        $assetOperation = new AssetOperation();
        $assetOperation->create = $asset;

        try {
            $assetServiceClient = $this->client->getAssetServiceClient();
            $response = $assetServiceClient->mutateAssets($customerId, [$assetOperation]);
            $newAssetResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully uploaded image asset: " . $newAssetResourceName);
            return $newAssetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error uploading image asset for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
