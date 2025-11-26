<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\TextAsset;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class CreateTextAsset extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, string $text): ?string
    {
        $this->ensureClient();

        // Check if asset already exists (optional optimization, skipping for now)

        $asset = new Asset([
            'text_asset' => new TextAsset(['text' => $text]),
            // 'name' => ... // Optional, auto-generated if omitted
        ]);

        $operation = new AssetOperation();
        $operation->setCreate($asset);

        try {
            $assetServiceClient = $this->client->getAssetServiceClient();
            $response = $assetServiceClient->mutateAssets(new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Text Asset: $resourceName ('$text')");

            return $resourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create Text Asset: " . $e->getMessage());
            return null;
        }
    }
}
