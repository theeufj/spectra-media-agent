<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Common\TextAsset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class CreateTextAsset extends BaseGoogleAdsService
{
    /**
     * Create a text asset (Headline, Description, etc.)
     *
     * @param string $customerId
     * @param string $text
     * @return string|null Resource name of the created asset
     */
    public function __invoke(string $customerId, string $text): ?string
    {
        $this->ensureClient();

        $asset = new Asset([
            'text_asset' => new TextAsset(['text' => $text]),
            // Note: We don't set a name for text assets usually, Google handles it or it's optional.
            // But for uniqueness, sometimes it's good to check if it exists.
            // For now, we just create it. Google Ads API handles deduplication for identical assets automatically in some contexts,
            // but explicitly creating a new one might return the existing one if it's identical?
            // Actually, creating a new asset with same content usually creates a new ID or returns existing.
            // Let's try creating.
        ]);

        $operation = new AssetOperation();
        $operation->setCreate($asset);

        try {
            $service = $this->client->getAssetServiceClient();
            $response = $service->mutateAssets(new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            //$this->logInfo("Created Text Asset: $resourceName ('$text')");

            return $resourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to create text asset: " . $e->getMessage());
            return null;
        }
    }
}
