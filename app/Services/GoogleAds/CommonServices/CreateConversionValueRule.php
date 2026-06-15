<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\ConversionValueRule;
use Google\Ads\GoogleAds\V22\Common\ValueRuleAction;
use Google\Ads\GoogleAds\V22\Common\ValueRuleAudienceCondition;
use Google\Ads\GoogleAds\V22\Common\ValueRuleDeviceCondition;
use Google\Ads\GoogleAds\V22\Common\ValueRuleGeoLocationCondition;
use Google\Ads\GoogleAds\V22\Services\ConversionValueRuleOperation;
use Google\Ads\GoogleAds\V22\Enums\ValueRuleDeviceTypeEnum\ValueRuleDeviceType;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * CreateConversionValueRule
 *
 * Creates a single ConversionValueRule that adjusts conversion values
 * based on device, audience, or geo conditions.
 *
 * $ruleConfig keys:
 *   'action'       => ['operation' => 'ADD', 'value' => 2.0]
 *   'audience'     => ['user_list' => 'customers/x/userLists/y']  (optional)
 *   'device'       => 'MOBILE'|'DESKTOP'|'TABLET'                 (optional)
 *   'geo_location' => ['geo_target_constant' => 'geoTargetConstants/1014044'] (optional)
 */
class CreateConversionValueRule extends BaseGoogleAdsService
{
    /**
     * @param  string $customerId
     * @param  array  $ruleConfig
     * @return string|null  Resource name of the created rule, or null on failure
     */
    public function __invoke(string $customerId, array $ruleConfig): ?string
    {
        $this->ensureClient();

        try {
            // Build the action (required)
            $actionConfig    = $ruleConfig['action'] ?? [];
            $operationValue  = $actionConfig['operation'] ?? 'ADD';
            $multiplierValue = (float) ($actionConfig['value'] ?? 1.0);

            $action = new ValueRuleAction([
                'operation' => $this->resolveValueRuleOperation($operationValue),
                'value'     => $multiplierValue,
            ]);

            $ruleArgs = ['action' => $action];

            // Optional: audience condition
            if (!empty($ruleConfig['audience']['user_list'])) {
                $ruleArgs['audience_condition'] = new ValueRuleAudienceCondition([
                    'user_lists' => [$ruleConfig['audience']['user_list']],
                ]);
            }

            // Optional: device condition
            if (!empty($ruleConfig['device'])) {
                $deviceType = $this->resolveDeviceType($ruleConfig['device']);
                $ruleArgs['device_condition'] = new ValueRuleDeviceCondition([
                    'device_types' => [$deviceType],
                ]);
            }

            // Optional: geo location condition
            if (!empty($ruleConfig['geo_location']['geo_target_constant'])) {
                $ruleArgs['geo_location_condition'] = new ValueRuleGeoLocationCondition([
                    'geo_target_constants' => [$ruleConfig['geo_location']['geo_target_constant']],
                ]);
            }

            $rule = new ConversionValueRule($ruleArgs);

            $operation = new ConversionValueRuleOperation();
            $operation->setCreate($rule);

            $serviceClient = $this->client->getConversionValueRuleServiceClient();
            $response = $serviceClient->mutateConversionValueRules($customerId, [$operation]);

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("CreateConversionValueRule: Created rule {$resourceName}");

            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            Log::error('CreateConversionValueRule: Failed to create rule', [
                'customer_id' => $customerId,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function resolveValueRuleOperation(string $operation): int
    {
        // ValueRuleOperation enum: 0=UNSPECIFIED, 1=UNKNOWN, 2=ADD, 3=MULTIPLY, 4=SET
        return match (strtoupper($operation)) {
            'ADD'      => 2,
            'MULTIPLY' => 3,
            'SET'      => 4,
            default    => 2,
        };
    }

    private function resolveDeviceType(string $device): int
    {
        return match (strtoupper($device)) {
            'MOBILE'  => ValueRuleDeviceType::MOBILE,
            'DESKTOP' => ValueRuleDeviceType::DESKTOP,
            'TABLET'  => ValueRuleDeviceType::TABLET,
            default   => ValueRuleDeviceType::MOBILE,
        };
    }
}
