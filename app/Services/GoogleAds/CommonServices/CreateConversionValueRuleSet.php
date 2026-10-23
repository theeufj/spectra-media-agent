<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
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
        $this->ensureClient();

        try {
            $ruleSet = new ConversionValueRuleSet([
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
