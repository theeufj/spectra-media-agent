<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V26\Common\CallAsset;
use Google\Ads\GoogleAds\V26\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V26\Enums\CallConversionReportingStateEnum\CallConversionReportingState;
use Google\Ads\GoogleAds\V26\Resources\Asset;
use Google\Ads\GoogleAds\V26\Services\AssetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateAssetsRequest;
use Illuminate\Support\Facades\Log;

class CreateCallAsset extends BaseGoogleAdsService
{
    /**
     * Create a Call Asset (phone extension).
     *
     * @param  string  $phoneNumber  The phone number
     * @param  string|null  $countryCode  Business numbering region for national numbers; international prefixes take precedence.
     * @return string|null Resource name of the created Asset
     */
    public function __invoke(string $customerId, string $phoneNumber, ?string $countryCode = null): ?string
    {
        if (! preg_match('/^[1-9][0-9]{9}$/D', $customerId) || $this->customer?->cleanGoogleCustomerId() !== $customerId) {
            $this->logError('Call asset refused: the customer account does not match.');

            return null;
        }
        $phone = (new CallAssetPhoneNumber)->resolve($phoneNumber, $countryCode ?? $this->customer->country);
        if ($phone === null) {
            Log::warning('Call asset omitted: the phone number and its numbering country could not be validated.');

            return null;
        }

        $callAsset = new CallAsset([
            'country_code' => $phone['country_code'],
            'phone_number' => $phone['phone_number'],
            'call_conversion_reporting_state' => CallConversionReportingState::DISABLED,
        ]);

        $asset = new Asset([
            'name' => 'Call: '.$phoneNumber.' - '.uniqid(),
            'type' => AssetType::CALL,
            'call_asset' => $callAsset,
        ]);

        $operation = new AssetOperation;
        $operation->setCreate($asset);

        try {
            $this->ensureClient();
            $response = $this->client->getAssetServiceClient()->mutateAssets(new MutateAssetsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            if ($this->dryRun) {
                $this->logInfo('Validated Call Asset; no asset was created.');

                return null;
            }
            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Created Call Asset ({$phone['country_code']}): {$resourceName}");

            return $resourceName;
        } catch (\Throwable $e) {
            $this->logError('Failed to create Call Asset: '.$e->getMessage(), $e);

            return null;
        }
    }
}
