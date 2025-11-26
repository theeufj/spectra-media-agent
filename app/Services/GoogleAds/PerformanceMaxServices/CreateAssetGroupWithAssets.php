<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AssetGroupStatusEnum\AssetGroupStatus;
use Google\Ads\GoogleAds\V22\Resources\AssetGroup;
use Google\Ads\GoogleAds\V22\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V22\Services\AssetGroupOperation;
use Google\Ads\GoogleAds\V22\Services\AssetGroupAssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateOperation;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class CreateAssetGroupWithAssets extends BaseGoogleAdsService
{
    /**
     * Create an Asset Group and link initial assets in a single transaction.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param string $name
     * @param array $finalUrls
     * @param array $assets Array of ['asset' => string, 'field_type' => int]
     * @return string|null Resource name of the created Asset Group
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $name, array $finalUrls, array $assets): ?string
    {
        $this->ensureClient();

        $operations = [];
        $tempId = -1; // Temporary ID for the Asset Group

        // 1. Create Asset Group Operation
        $assetGroup = new AssetGroup([
            'resource_name' => "customers/$customerId/assetGroups/$tempId",
            'name' => $name,
            'campaign' => $campaignResourceName,
            'final_urls' => $finalUrls,
            'status' => AssetGroupStatus::PAUSED,
        ]);

        $assetGroupOperation = new AssetGroupOperation();
        $assetGroupOperation->setCreate($assetGroup);

        $mutateOperation = new MutateOperation();
        $mutateOperation->setAssetGroupOperation($assetGroupOperation);
        $operations[] = $mutateOperation;

        // 2. Create Asset Group Asset Operations
        foreach ($assets as $assetData) {
            $assetGroupAsset = new AssetGroupAsset([
                'asset_group' => "customers/$customerId/assetGroups/$tempId",
                'asset' => $assetData['asset'],
                'field_type' => $assetData['field_type'],
            ]);

            $assetGroupAssetOperation = new AssetGroupAssetOperation();
            $assetGroupAssetOperation->setCreate($assetGroupAsset);

            $mutateOperation = new MutateOperation();
            $mutateOperation->setAssetGroupAssetOperation($assetGroupAssetOperation);
            $operations[] = $mutateOperation;
        }

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $response = $googleAdsServiceClient->mutate(new MutateGoogleAdsRequest([
                'customer_id' => $customerId,
                'mutate_operations' => $operations,
            ]));

            // The first result corresponds to the Asset Group creation
            $assetGroupResourceName = $response->getMutateOperationResponses()[0]->getAssetGroupResult()->getResourceName();
            $this->logInfo("Created Asset Group with Assets: $assetGroupResourceName");

            return $assetGroupResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create Asset Group with Assets: " . $e->getMessage());
            return null;
        }
    }
}
