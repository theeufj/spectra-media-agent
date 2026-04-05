<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\CallAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Enums\CallConversionReportingStateEnum\CallConversionReportingState;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class CreateCallAsset extends BaseGoogleAdsService
{
    /**
     * Create a Call Asset (phone extension).
     *
     * @param string $customerId
     * @param string $phoneNumber The phone number
     * @param string $countryCode ISO country code (e.g. 'AU', 'US')
     * @return string|null Resource name of the created Asset
     */
    public function __invoke(string $customerId, string $phoneNumber, string $countryCode = 'AU'): ?string
    {
        $this->ensureClient();

        $callAsset = new CallAsset([
            'country_code' => $countryCode,
            'phone_number' => $phoneNumber,
            'call_conversion_reporting_state' => CallConversionReportingState::DISABLED,
        ]);

        $asset = new Asset([
            'name' => 'Call: ' . $phoneNumber . ' - ' . uniqid(),
            'type' => AssetType::CALL,
            'call_asset' => $callAsset,
        ]);

        $operation = new AssetOperation();
        $operation->setCreate($asset);

        try {
            $response = $this->client->getAssetServiceClient()->mutateAssets(new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Call Asset ({$countryCode} {$phoneNumber}): {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to create Call Asset: " . $e->getMessage());
            return null;
        }
    }
}
