<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\Creative\GoogleCreativeCta;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Common\CallToActionAsset;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\AssetGroupStatusEnum\AssetGroupStatus;
use Google\Ads\GoogleAds\V26\Resources\Asset;
use Google\Ads\GoogleAds\V26\Resources\AssetGroup;
use Google\Ads\GoogleAds\V26\Resources\AssetGroupAsset;
use Google\Ads\GoogleAds\V26\Services\AssetGroupAssetOperation;
use Google\Ads\GoogleAds\V26\Services\AssetGroupOperation;
use Google\Ads\GoogleAds\V26\Services\AssetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateOperation;

class CreateAssetGroupWithAssets extends BaseGoogleAdsService
{
    /**
     * Create an Asset Group and link initial assets in a single transaction.
     *
     * @param  array  $assets  Array of ['asset' => string, 'field_type' => int]
     * @return string|null Resource name of the created Asset Group
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $name, array $finalUrls, array $assets, ?string $callToAction = null): ?string
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
            'status' => AssetGroupStatus::ENABLED,
        ]);

        $assetGroupOperation = new AssetGroupOperation;
        $assetGroupOperation->setCreate($assetGroup);

        $mutateOperation = new MutateOperation;
        $mutateOperation->setAssetGroupOperation($assetGroupOperation);
        $operations[] = $mutateOperation;

        // 2. Create Asset Group Asset Operations
        foreach ($assets as $assetData) {
            $assetGroupAsset = new AssetGroupAsset([
                'asset_group' => "customers/$customerId/assetGroups/$tempId",
                'asset' => $assetData['asset'],
                'field_type' => $assetData['field_type'],
            ]);

            $assetGroupAssetOperation = new AssetGroupAssetOperation;
            $assetGroupAssetOperation->setCreate($assetGroupAsset);

            $mutateOperation = new MutateOperation;
            $mutateOperation->setAssetGroupAssetOperation($assetGroupAssetOperation);
            $operations[] = $mutateOperation;
        }

        if ($callToAction !== null) {
            array_push($operations, ...$this->ctaOperations($customerId, $assetGroup->getResourceName(), $callToAction));
        }

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $response = $googleAdsServiceClient->mutate(new MutateGoogleAdsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'mutate_operations' => $operations,
            ]));

            // The first result corresponds to the Asset Group creation
            $assetGroupResourceName = $response->getMutateOperationResponses()[0]->getAssetGroupResult()->getResourceName();
            $this->logInfo("Created Asset Group with Assets: $assetGroupResourceName");

            return $assetGroupResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError('Failed to create Asset Group with Assets: '.$e->getMessage());

            return null;
        }
    }

    public function attachCallToAction(string $customerId, string $group, string $label): void
    {
        $this->ensureClient();
        $this->client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
            'customer_id' => $customerId, 'validate_only' => $this->dryRun,
            'mutate_operations' => $this->ctaOperations($customerId, $group, $label),
        ]));
    }

    /** Create and link the enum-backed CTA atomically. It is separate from headline text. */
    private function ctaOperations(string $customerId, string $group, string $label): array
    {
        $asset = new Asset([
            'resource_name' => "customers/{$customerId}/assets/-2",
            'call_to_action_asset' => new CallToActionAsset(['call_to_action' => GoogleCreativeCta::type($label)]),
        ]);
        $link = new AssetGroupAsset([
            'asset_group' => $group, 'asset' => $asset->getResourceName(), 'field_type' => AssetFieldType::CALL_TO_ACTION_SELECTION,
        ]);

        return [
            new MutateOperation(['asset_operation' => new AssetOperation(['create' => $asset])]),
            new MutateOperation(['asset_group_asset_operation' => new AssetGroupAssetOperation(['create' => $link])]),
        ];
    }
}
