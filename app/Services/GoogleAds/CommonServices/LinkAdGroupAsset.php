<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAsset;
use Google\Ads\GoogleAds\V22\Services\AdGroupAssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupAssetsRequest;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V22\Enums\AssetLinkStatusEnum\AssetLinkStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class LinkAdGroupAsset extends BaseGoogleAdsService
{
    public function __construct(Customer $customer, bool $useMccCredentials = false)
    {
        parent::__construct($customer, $useMccCredentials);
    }

    /**
     * Links an asset to an ad group.
     *
     * @param string $customerId
     * @param string $adGroupResourceName
     * @param string $assetResourceName
     * @param int $fieldType The AssetFieldType enum value (e.g., AssetFieldType::MARKETING_IMAGE)
     * @return string|null The resource name of the created AdGroupAsset, or null on failure.
     */
    public function __invoke(string $customerId, string $adGroupResourceName, string $assetResourceName, int $fieldType = AssetFieldType::MARKETING_IMAGE): ?string
    {
        $adGroupAsset = new AdGroupAsset([
            'ad_group' => $adGroupResourceName,
            'asset' => $assetResourceName,
            'field_type' => $fieldType,
            'status' => AssetLinkStatus::ENABLED,
        ]);

        $operation = new AdGroupAssetOperation();
        $operation->setCreate($adGroupAsset);

        try {
            $adGroupAssetServiceClient = $this->client->getAdGroupAssetServiceClient();
            $request = new MutateAdGroupAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]);
            
            $response = $adGroupAssetServiceClient->mutateAdGroupAssets($request);
            $resourceName = $response->getResults()[0]->getResourceName();
            
            $this->logInfo("Successfully linked asset to ad group: $resourceName");
            
            return $resourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error linking asset to ad group: " . $e->getMessage(), $e);
            return null;
        }
    }
}
