<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V26\Enums\ValueRuleSetAttachmentTypeEnum\ValueRuleSetAttachmentType;
use Google\Ads\GoogleAds\V26\Enums\ValueRuleSetDimensionEnum\ValueRuleSetDimension;
use Google\Ads\GoogleAds\V26\Resources\ConversionValueRuleSet;
use Google\Ads\GoogleAds\V26\Services\ConversionValueRuleSetOperation;
use Google\Ads\GoogleAds\V26\Services\MutateConversionValueRuleSetsRequest;
use Illuminate\Support\Facades\Log;

/**
 * CreateConversionValueRuleSet
 *
 * Creates a ConversionValueRuleSet and attaches it to a campaign,
 * bundling one or more ConversionValueRule resources together.
 */
class CreateConversionValueRuleSet extends BaseGoogleAdsService
{
    /**
     * @param  string  $campaignResourceName  e.g. "customers/123/campaigns/456"
     * @param  string[]  $ruleResourceNames  Array of ConversionValueRule resource names
     * @param  int[]  $dimensions  Condition dimensions shared by the included rules
     * @return string|null Resource name of the created rule set, or null on failure
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        array $ruleResourceNames,
        array $dimensions = [ValueRuleSetDimension::NO_CONDITION]
    ): ?string {
        if (! preg_match('/^[1-9][0-9]{9}$/D', $customerId)
            || $this->customer?->cleanGoogleCustomerId() !== $customerId
            || ! preg_match('#^customers/'.$customerId.'/campaigns/[1-9][0-9]*$#D', $campaignResourceName)
            || $ruleResourceNames === [] || $dimensions === []
            || array_filter($ruleResourceNames, fn ($rule) => ! preg_match('#^customers/'.$customerId.'/conversionValueRules/[1-9][0-9]*$#D', $rule)) !== []
            || array_filter($dimensions, fn ($dimension) => ! in_array($dimension, [ValueRuleSetDimension::NO_CONDITION,
                ValueRuleSetDimension::DEVICE, ValueRuleSetDimension::AUDIENCE, ValueRuleSetDimension::GEO_LOCATION,
                ValueRuleSetDimension::ITINERARY], true)) !== []) {
            $this->logError('Conversion value rule set refused: account, campaign, rule resources or dimensions are invalid.');

            return null;
        }

        try {
            $this->ensureClient();
            $ruleSet = new ConversionValueRuleSet([
                'attachment_type' => ValueRuleSetAttachmentType::CAMPAIGN,
                'conversion_value_rules' => $ruleResourceNames,
                'campaign' => $campaignResourceName,
                'dimensions' => $dimensions,
            ]);

            $operation = new ConversionValueRuleSetOperation;
            $operation->setCreate($ruleSet);

            $serviceClient = $this->client->getConversionValueRuleSetServiceClient();
            $response = $serviceClient->mutateConversionValueRuleSets(new MutateConversionValueRuleSetsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [$operation],
            ]));

            if ($this->dryRun) {
                $this->logInfo('Validated conversion value rule set; no rule set was created.');

                return null;
            }
            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("CreateConversionValueRuleSet: Created rule set {$resourceName}");

            return $resourceName;
        } catch (\Throwable $e) {
            // \Throwable, not GoogleAdsException|ApiException: a wrong call
            // shape throws a TypeError, which is an \Error and sailed straight
            // past the old catch as a fatal.
            report($e);
            Log::error('CreateConversionValueRuleSet: Failed to create rule set', [
                'customer_id' => $customerId,
                'campaign' => $campaignResourceName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
