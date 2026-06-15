<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\BiddingSeasonalityAdjustment;
use Google\Ads\GoogleAds\V22\Services\BiddingSeasonalityAdjustmentOperation;
use Google\Ads\GoogleAds\V22\Enums\SeasonalityEventScopeEnum\SeasonalityEventScope;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * CreateSeasonalityAdjustment
 *
 * Creates a BiddingSeasonalityAdjustment that tells Google's Smart Bidding
 * to expect a change in conversion rates during a defined time window.
 *
 * $config keys:
 *   'name'                       => string  (e.g. "Black Friday 2026")
 *   'scope'                      => 'CAMPAIGN'|'CHANNEL'  (default 'CAMPAIGN')
 *   'start_date_time'            => string  "yyyy-MM-dd HH:mm:ss"
 *   'end_date_time'              => string  "yyyy-MM-dd HH:mm:ss"
 *   'conversion_rate_modifier'   => float   (e.g. 1.30 for +30% expected uplift)
 *   'campaign_resource'          => string|null  (required when scope=CAMPAIGN)
 *   'advertising_channel_type'   => string|null  (required when scope=CHANNEL, e.g. 'SEARCH')
 */
class CreateSeasonalityAdjustment extends BaseGoogleAdsService
{
    /**
     * @param  string $customerId
     * @param  array  $config
     * @return string|null  Resource name of the created adjustment, or null on failure
     */
    public function __invoke(string $customerId, array $config): ?string
    {
        $this->ensureClient();

        try {
            $scope = strtoupper($config['scope'] ?? 'CAMPAIGN');
            $scopeEnum = match ($scope) {
                'CHANNEL' => SeasonalityEventScope::CHANNEL,
                default   => SeasonalityEventScope::CAMPAIGN,
            };

            $adjustmentArgs = [
                'name'                     => $config['name'] ?? 'Seasonality Adjustment',
                'scope'                    => $scopeEnum,
                'start_date_time'          => $config['start_date_time'],
                'end_date_time'            => $config['end_date_time'],
                'conversion_rate_modifier' => (float) ($config['conversion_rate_modifier'] ?? 1.0),
            ];

            // Attach the campaign resource when scope is CAMPAIGN
            if ($scopeEnum === SeasonalityEventScope::CAMPAIGN && !empty($config['campaign_resource'])) {
                $adjustmentArgs['campaigns'] = [$config['campaign_resource']];
            }

            // Attach channel type when scope is CHANNEL
            if ($scopeEnum === SeasonalityEventScope::CHANNEL && !empty($config['advertising_channel_type'])) {
                $channelType = $this->resolveChannelType($config['advertising_channel_type']);
                $adjustmentArgs['advertising_channel_types'] = [$channelType];
            }

            $adjustment = new BiddingSeasonalityAdjustment($adjustmentArgs);

            $operation = new BiddingSeasonalityAdjustmentOperation();
            $operation->setCreate($adjustment);

            $serviceClient = $this->client->getBiddingSeasonalityAdjustmentServiceClient();
            $response = $serviceClient->mutateBiddingSeasonalityAdjustments($customerId, [$operation]);

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("CreateSeasonalityAdjustment: Created adjustment {$resourceName}");

            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            Log::error('CreateSeasonalityAdjustment: Failed to create adjustment', [
                'customer_id' => $customerId,
                'config'      => $config,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function resolveChannelType(string $type): int
    {
        return match (strtoupper($type)) {
            'SEARCH'       => AdvertisingChannelType::SEARCH,
            'DISPLAY'      => AdvertisingChannelType::DISPLAY,
            'SHOPPING'     => AdvertisingChannelType::SHOPPING,
            'HOTEL'        => AdvertisingChannelType::HOTEL,
            'VIDEO'        => AdvertisingChannelType::VIDEO,
            'MULTI_CHANNEL' => AdvertisingChannelType::MULTI_CHANNEL,
            'LOCAL'        => AdvertisingChannelType::LOCAL,
            'SMART'        => AdvertisingChannelType::SMART,
            'PERFORMANCE_MAX' => AdvertisingChannelType::PERFORMANCE_MAX,
            'LOCAL_SERVICES'  => AdvertisingChannelType::LOCAL_SERVICES,
            default        => AdvertisingChannelType::SEARCH,
        };
    }
}
