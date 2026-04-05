<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\Money;
use Google\Ads\GoogleAds\V22\Common\PriceAsset;
use Google\Ads\GoogleAds\V22\Common\PriceOffering;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreatePriceAsset extends BaseGoogleAdsService
{
    /**
     * Create a Price Asset.
     *
     * @param string $customerId
     * @param int $type PriceExtensionTypeEnum (BRANDS=2, EVENTS=3, LOCATIONS=4, NEIGHBORHOODS=5, PRODUCT_CATEGORIES=6, PRODUCT_TIERS=7, SERVICES=8, SERVICE_CATEGORIES=9, SERVICE_TIERS=10)
     * @param int $priceQualifier PriceExtensionPriceQualifierEnum (FROM=2, UP_TO=3, AVERAGE=4)
     * @param array $offerings Array of ['header'=>, 'description'=>, 'price_micros'=>, 'currency_code'=>, 'unit'=>, 'final_url'=>]
     * @param string $languageCode BCP 47 language tag
     * @return string|null Resource name of the created Asset
     */
    public function __invoke(
        string $customerId,
        int $type,
        int $priceQualifier,
        array $offerings,
        string $languageCode = 'en'
    ): ?string {
        $this->ensureClient();

        $priceOfferings = [];
        foreach ($offerings as $offering) {
            $priceOfferings[] = new PriceOffering([
                'header' => $offering['header'],
                'description' => $offering['description'],
                'price' => new Money([
                    'amount_micros' => $offering['price_micros'],
                    'currency_code' => $offering['currency_code'] ?? 'AUD',
                ]),
                'unit' => $offering['unit'] ?? 0, // UNSPECIFIED
                'final_url' => $offering['final_url'],
            ]);
        }

        $priceAsset = new PriceAsset([
            'type' => $type,
            'price_qualifier' => $priceQualifier,
            'language_code' => $languageCode,
            'price_offerings' => $priceOfferings,
        ]);

        $asset = new Asset([
            'name' => 'Price: ' . count($offerings) . ' offerings - ' . uniqid(),
            'type' => AssetType::PRICE,
            'price_asset' => $priceAsset,
        ]);

        $operation = new AssetOperation();
        $operation->setCreate($asset);

        try {
            $response = $this->client->getAssetServiceClient()->mutateAssets(new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Price Asset with " . count($offerings) . " offerings: {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to create Price Asset: " . $e->getMessage());
            return null;
        }
    }
}
