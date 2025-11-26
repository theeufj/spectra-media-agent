<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V22\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V22\Services\AssetGroupAssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetGroupAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class LinkAssetGroupAsset extends BaseGoogleAdsService
{
    /**
     * Link an asset to an Asset Group.
     *
     * @param string $customerId
     * @param string $assetGroupResourceName
     * @param string $assetResourceName
     * @param int $fieldType AssetFieldType enum value (e.g., HEADLINE, DESCRIPTION, MARKETING_IMAGE)
     * @return string|null Resource name of the created link
     */
    public function __invoke(string $customerId, string $assetGroupResourceName, string $assetResourceName, int $fieldType): ?string
    {
        $this->ensureClient();

        $assetGroupAsset = new AssetGroupAsset([
            'asset_group' => $assetGroupResourceName,
            'asset' => $assetResourceName,
            'field_type' => $fieldType,
        ]);

        $operation = new AssetGroupAssetOperation();
        $operation->setCreate($assetGroupAsset);

        try {
            $service = $this->client->getAssetGroupAssetServiceClient();
            $response = $service->mutateAssetGroupAssets(new MutateAssetGroupAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Linked Asset to Asset Group: $resourceName (Type: $fieldType)");

            return $resourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to link asset to asset group: " . $e->getMessage());
            return null;
        }
    }
}
