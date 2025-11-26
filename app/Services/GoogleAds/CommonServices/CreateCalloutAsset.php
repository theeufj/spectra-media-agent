<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\CalloutAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class CreateCalloutAsset extends BaseGoogleAdsService
{
    /**
     * Create a Callout Asset.
     *
     * @param string $customerId
     * @param string $calloutText The text for the callout (max 25 chars)
     * @return string|null Resource name of the created Asset
     */
    public function __invoke(string $customerId, string $calloutText): ?string
    {
        $this->ensureClient();

        $calloutAsset = new CalloutAsset([
            'callout_text' => $calloutText,
        ]);

        $asset = new Asset([
            'name' => 'Callout: ' . $calloutText . ' - ' . uniqid(),
            'type' => AssetType::CALLOUT,
            'callout_asset' => $calloutAsset,
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
            $this->logInfo("Created Callout Asset: $assetResourceName");

            return $assetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create Callout Asset: " . $e->getMessage());
            return null;
        }
    }
}
