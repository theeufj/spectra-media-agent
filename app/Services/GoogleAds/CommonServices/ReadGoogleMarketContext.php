<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;

/** Account-scoped read-only provenance for pre-approval forecasts. */
class ReadGoogleMarketContext extends BaseGoogleAdsService
{
    public function __invoke(): array
    {
        $id = $this->customer?->cleanGoogleCustomerId() ?? '';
        if (! ctype_digit($id)) {
            throw new \RuntimeException('A real customer Google Ads account is required for market estimates.');
        }
        $this->ensureClient();
        foreach ($this->searchQuery($id, 'SELECT customer.id, customer.resource_name, customer.currency_code, customer.time_zone FROM customer LIMIT 1')->iterateAllElements() as $row) {
            $customer = $row->getCustomer();
            if ((string) $customer->getId() !== $id || $customer->getResourceName() !== 'customers/'.$id
                || ! preg_match('/^[A-Z]{3}$/', $customer->getCurrencyCode())
                || ! in_array($customer->getTimeZone(), timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
                break;
            }

            return ['customer_id' => $id, 'currency_code' => $customer->getCurrencyCode(),
                'time_zone' => $customer->getTimeZone(), 'account_time_zone_verified' => true,
                'checked_at' => now()->toIso8601String()];
        }

        throw new \RuntimeException('Google account currency and timezone could not be verified.');
    }
}
