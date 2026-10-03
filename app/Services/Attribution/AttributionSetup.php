<?php

namespace App\Services\Attribution;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Models\Customer;

class AttributionSetup
{
    /** @return array<string, string|null> */
    public function forCustomer(Customer $customer): array
    {
        $websiteHost = parse_url((string) $customer->website, PHP_URL_HOST);
        $scriptUrl = rtrim((string) config('app.url'), '/').'/js/spectra-pixel.js';

        return [
            'website_host' => is_string($websiteHost) ? $websiteHost : null,
            'snippet' => $websiteHost ? '<script src="'.$scriptUrl.'" data-site-id="'.$customer->uuid.'" defer></script>' : null,
            'last_visit_at' => AttributionTouchpoint::forCustomer($customer->id)->max('touched_at'),
            'last_conversion_at' => AttributionConversion::forCustomer($customer->id)->max('created_at'),
        ];
    }
}
