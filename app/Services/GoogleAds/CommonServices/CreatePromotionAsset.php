<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\Money;
use Google\Ads\GoogleAds\V22\Common\PromotionAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreatePromotionAsset extends BaseGoogleAdsService
{
    /**
     * Create a Promotion Asset.
     *
     * @param string $customerId
     * @param string $promotionTarget e.g. "Summer Sale", "20% Off All Services"
     * @param array $promotionData [
     *   'percent_off' => 200_000 (int, 1,000,000 = 100%, so 200_000 = 20%),
     *   OR 'money_amount_off' => ['amount_micros' => 10_000_000, 'currency_code' => 'AUD'],
     *   'occasion' => int (PromotionExtensionOccasionEnum, optional),
     *   'discount_modifier' => int (PromotionExtensionDiscountModifierEnum, optional),
     *   'start_date' => 'yyyy-MM-dd' (optional),
     *   'end_date' => 'yyyy-MM-dd' (optional),
     *   'language_code' => 'en' (optional),
     *   'final_url' => 'https://...' (optional),
     *   'promotion_code' => 'SAVE20' (optional),
     * ]
     * @return string|null Resource name of the created Asset
     */
    public function __invoke(string $customerId, string $promotionTarget, array $promotionData): ?string
    {
        $this->ensureClient();

        $assetData = [
            'promotion_target' => $promotionTarget,
            'language_code' => $promotionData['language_code'] ?? 'en',
        ];

        if (isset($promotionData['percent_off'])) {
            $assetData['percent_off'] = $promotionData['percent_off'];
        } elseif (isset($promotionData['money_amount_off'])) {
            $assetData['money_amount_off'] = new Money($promotionData['money_amount_off']);
        }

        if (isset($promotionData['occasion'])) {
            $assetData['occasion'] = $promotionData['occasion'];
        }
        if (isset($promotionData['discount_modifier'])) {
            $assetData['discount_modifier'] = $promotionData['discount_modifier'];
        }
        if (isset($promotionData['start_date'])) {
            $assetData['start_date'] = $promotionData['start_date'];
        }
        if (isset($promotionData['end_date'])) {
            $assetData['end_date'] = $promotionData['end_date'];
        }
        if (isset($promotionData['promotion_code'])) {
            $assetData['promotion_code'] = $promotionData['promotion_code'];
        }
        if (isset($promotionData['orders_over_amount'])) {
            $assetData['orders_over_amount'] = new Money($promotionData['orders_over_amount']);
        }

        $promotionAsset = new PromotionAsset($assetData);

        $assetFields = [
            'name' => 'Promotion: ' . $promotionTarget . ' - ' . uniqid(),
            'type' => AssetType::PROMOTION,
            'promotion_asset' => $promotionAsset,
        ];

        if (isset($promotionData['final_url'])) {
            $assetFields['final_urls'] = [$promotionData['final_url']];
        }

        $asset = new Asset($assetFields);
        $operation = new AssetOperation();
        $operation->setCreate($asset);

        try {
            $response = $this->client->getAssetServiceClient()->mutateAssets(new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Promotion Asset ({$promotionTarget}): {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to create Promotion Asset: " . $e->getMessage());
            return null;
        }
    }
}
