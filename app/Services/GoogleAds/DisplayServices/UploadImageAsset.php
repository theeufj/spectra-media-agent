<?php

namespace App\Services\GoogleAds\DisplayServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Common\ImageAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Services\AssetService;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class UploadImageAsset extends BaseGoogleAdsService
{
    public function __construct(Customer $customer, bool $useMccCredentials = false)
    {
        parent::__construct($customer, $useMccCredentials);
    }

    /**
     * Uploads an image file to be used as an asset in Responsive Display Ads.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $imageData The raw image data.
     * @param string $imageFileName The name of the image file (e.g., 'my_image.png').
     * @return string|null The resource name of the uploaded image asset, or null on failure.
     */
    public function __invoke(string $customerId, string $imageData, string $imageFileName): ?string
    {
        // Check if asset with this name already exists
        $existingAsset = $this->getAssetByName($customerId, $imageFileName);
        if ($existingAsset) {
            $this->logInfo("Asset '{$imageFileName}' already exists. Skipping upload.");
            return $existingAsset->getResourceName();
        }

        // Create ImageAsset
        $imageAsset = new ImageAsset(['data' => $imageData]);

        // Create Asset
        $asset = new Asset([
            'name' => $imageFileName,
            'type' => AssetType::IMAGE,
            'image_asset' => $imageAsset,
        ]);

        // Create AssetOperation
        $assetOperation = new AssetOperation();
        $assetOperation->setCreate($asset);

        try {
            $assetServiceClient = $this->client->getAssetServiceClient();
            $request = new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$assetOperation],
            ]);
            $response = $assetServiceClient->mutateAssets($request);
            $newAssetResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully uploaded image asset: " . $newAssetResourceName);
            return $newAssetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error uploading image asset for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }

    /**
     * Check if an asset with the given name already exists
     */
    private function getAssetByName(string $customerId, string $assetName): ?Asset
    {
        $query = "SELECT asset.resource_name, asset.name FROM asset WHERE asset.name = '{$assetName}' AND asset.type = 'IMAGE'";
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $request = new \Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ]);
            $response = $googleAdsServiceClient->search($request);

            foreach ($response->getIterator() as $googleAdsRow) {
                return $googleAdsRow->getAsset();
            }
        } catch (GoogleAdsException $e) {
            $this->logError("Error fetching asset by name for customer $customerId: " . $e->getMessage(), $e);
        }

        return null;
    }
}
