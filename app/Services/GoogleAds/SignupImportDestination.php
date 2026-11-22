<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use App\Models\Setting;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCountingTypeEnum\ConversionActionCountingType;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V26\Enums\EnhancedConversionsConfigEnum\EnhancedConversionsConfig;

/** Verify the site's own import action before sending a registration. */
class SignupImportDestination extends BaseGoogleAdsService
{
    public function __construct()
    {
        parent::__construct(new Customer(['google_ads_customer_id' => config('conversions.google_ads_customer_id')]));
    }

    public function allowsEmail(string $resource, array $clicks): bool
    {
        $account = $this->customer->cleanGoogleCustomerId();
        if (! ctype_digit($account) || ! preg_match('~^customers/'.preg_quote($account, '~').'/conversionActions/\d+$~D', $resource)
            || $resource !== Setting::get('conversion_resource_name.signup_import')) {
            throw new \RuntimeException('The saved signup destination differs from the configured Site to Spend account or action.');
        }
        $this->ensureClient();
        $query = "SELECT conversion_action.resource_name, conversion_action.owner_customer, conversion_action.status, conversion_action.type, conversion_action.category, conversion_action.counting_type FROM conversion_action WHERE conversion_action.resource_name = '{$resource}'";
        $action = $this->searchQuery($account, $query)->iterateAllElements()->current()?->getConversionAction();
        if (! $action || $action->getResourceName() !== $resource || $action->getOwnerCustomer() !== "customers/{$account}"
            || $action->getType() !== ConversionActionType::UPLOAD_CLICKS || $action->getCategory() !== ConversionActionCategory::SIGNUP
            || $action->getStatus() !== ConversionActionStatus::ENABLED) {
            throw new \RuntimeException('The signup destination must be the enabled signup import action owned by Site to Spend.');
        }
        if ((isset($clicks['gbraid']) || isset($clicks['wbraid'])) && $action->getCountingType() !== ConversionActionCountingType::MANY_PER_CLICK) {
            throw new \RuntimeException('Google requires the signup import to count every event for GBRAID or WBRAID. Registration IDs prevent duplicate uploads.');
        }
        $query = 'SELECT customer.conversion_tracking_setting.google_ads_conversion_customer, customer.conversion_tracking_setting.accepted_customer_data_terms, customer.conversion_tracking_setting.effective_enablement_setting FROM customer LIMIT 1';
        $tracking = $this->searchQuery($account, $query)->iterateAllElements()->current()?->getCustomer()?->getConversionTrackingSetting();

        return $tracking && $tracking->getGoogleAdsConversionCustomer() === "customers/{$account}" && $tracking->getAcceptedCustomerDataTerms()
            && $tracking->getEffectiveEnablementSetting() === EnhancedConversionsConfig::ON;
    }
}
