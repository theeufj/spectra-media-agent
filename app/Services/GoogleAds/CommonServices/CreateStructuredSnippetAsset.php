<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\StructuredSnippetAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreateStructuredSnippetAsset extends BaseGoogleAdsService
{
    /**
     * Create a Structured Snippet Asset.
     *
     * @param string $customerId
     * @param string $header e.g. "Services", "Brands", "Types", "Destinations"
     * @param array $values Array of snippet values (3-10 items)
     * @return string|null Resource name of the created Asset
     */
    public function __invoke(string $customerId, string $header, array $values): ?string
    {
        $this->ensureClient();

        $snippetAsset = new StructuredSnippetAsset([
            'header' => $header,
            'values' => $values,
        ]);

        $asset = new Asset([
            'name' => 'Snippet: ' . $header . ' - ' . uniqid(),
            'type' => AssetType::STRUCTURED_SNIPPET,
            'structured_snippet_asset' => $snippetAsset,
        ]);

        $operation = new AssetOperation();
        $operation->setCreate($asset);

        try {
            $response = $this->client->getAssetServiceClient()->mutateAssets(new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Structured Snippet Asset ({$header}): {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to create Structured Snippet Asset: " . $e->getMessage());
            return null;
        }
    }
}
