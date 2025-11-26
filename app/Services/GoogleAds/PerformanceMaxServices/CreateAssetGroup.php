<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AssetGroupStatusEnum\AssetGroupStatus;
use Google\Ads\GoogleAds\V22\Resources\AssetGroup;
use Google\Ads\GoogleAds\V22\Services\AssetGroupOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetGroupsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class CreateAssetGroup extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, string $campaignResourceName, string $name, array $finalUrls): ?string
    {
        $this->ensureClient();

        $assetGroup = new AssetGroup([
            'name' => $name,
            'campaign' => $campaignResourceName,
            'final_urls' => $finalUrls,
            'status' => AssetGroupStatus::PAUSED,
        ]);

        $operation = new AssetGroupOperation();
        $operation->setCreate($assetGroup);

        try {
            $assetGroupServiceClient = $this->client->getAssetGroupServiceClient();
            $response = $assetGroupServiceClient->mutateAssetGroups(new MutateAssetGroupsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Asset Group: $resourceName");

            return $resourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create Asset Group: " . $e->getMessage());
            return null;
        }
    }
}
