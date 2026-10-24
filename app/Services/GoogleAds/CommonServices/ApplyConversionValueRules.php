<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Illuminate\Support\Facades\Log;

/** Automatic deployment must not invent adjustments to measured conversion values. */
class ApplyConversionValueRules extends BaseGoogleAdsService
{
    public function __invoke(string $customerId, string $campaignResourceName, Customer $customer): bool
    {
        // No reviewed value-rule configuration exists in the campaign contract.
        // Keep the hook for callers, but do not manufacture mobile/audience
        // adjustments or attach existing rules. Explicit low-level adapters
        // remain available to a future reviewed configuration workflow.
        Log::info('ApplyConversionValueRules: Skipped; no explicitly approved value-rule configuration is available.', [
            'customer_id' => $customerId, 'campaign' => $campaignResourceName,
        ]);

        return false;
    }
}
