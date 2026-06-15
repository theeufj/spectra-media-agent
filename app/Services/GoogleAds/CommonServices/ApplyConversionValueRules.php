<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Illuminate\Support\Facades\Log;

/**
 * ApplyConversionValueRules
 *
 * Orchestrator that creates device and optional audience ConversionValueRules
 * for a campaign, then bundles them into a ConversionValueRuleSet.
 *
 * - Always creates a MOBILE device rule at 1.2× (mobile conversions worth 20% more for local businesses).
 * - If the customer has a CRM audience list (google_ads_customer_match_list_id), adds a 1.5× audience rule.
 */
class ApplyConversionValueRules extends BaseGoogleAdsService
{
    /**
     * @param  string   $customerId
     * @param  string   $campaignResourceName
     * @param  Customer $customer
     * @return bool
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        Customer $customer
    ): bool {
        $this->ensureClient();

        try {
            $ruleResourceNames = [];
            $createRule = new CreateConversionValueRule($this->customer);

            // Always create a MOBILE device rule (1.2× multiplier)
            $mobileRuleResource = ($createRule)($customerId, [
                'action' => ['operation' => 'ADD', 'value' => 1.2],
                'device' => 'MOBILE',
            ]);

            if ($mobileRuleResource) {
                $ruleResourceNames[] = $mobileRuleResource;
                Log::info('ApplyConversionValueRules: Created mobile device rule', [
                    'rule' => $mobileRuleResource,
                ]);
            }

            // Optional audience rule if customer has a CRM match list
            $userListId = $customer->google_ads_customer_match_list_id ?? null;
            if ($userListId) {
                $audienceRuleResource = ($createRule)($customerId, [
                    'action'   => ['operation' => 'ADD', 'value' => 1.5],
                    'audience' => ['user_list' => $userListId],
                ]);

                if ($audienceRuleResource) {
                    $ruleResourceNames[] = $audienceRuleResource;
                    Log::info('ApplyConversionValueRules: Created audience rule', [
                        'rule'      => $audienceRuleResource,
                        'user_list' => $userListId,
                    ]);
                }
            }

            if (empty($ruleResourceNames)) {
                Log::warning('ApplyConversionValueRules: No rules were created, skipping rule set');
                return false;
            }

            // Bundle into a ConversionValueRuleSet and attach to the campaign
            $createRuleSet = new CreateConversionValueRuleSet($this->customer);
            $ruleSetResource = ($createRuleSet)($customerId, $campaignResourceName, $ruleResourceNames);

            if (!$ruleSetResource) {
                Log::warning('ApplyConversionValueRules: Failed to create rule set for campaign', [
                    'campaign' => $campaignResourceName,
                ]);
                return false;
            }

            Log::info('ApplyConversionValueRules: Successfully applied conversion value rules', [
                'campaign'    => $campaignResourceName,
                'rule_set'    => $ruleSetResource,
                'rule_count'  => count($ruleResourceNames),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('ApplyConversionValueRules: Unexpected error', [
                'customer_id' => $customerId,
                'campaign'    => $campaignResourceName,
                'error'       => $e->getMessage(),
            ]);
            return false;
        }
    }
}
