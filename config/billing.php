<?php

return [

    /*
     * Ad spend is billed through the managed prepaid-credit system
     * (App\Jobs\ProcessDailyAdSpendBilling + App\Models\AdSpendCredit). The legacy
     * Stripe-metered path (billing:report-ad-spend) is a second, mutually-exclusive
     * billing model kept OFF by default so the two can never double-charge the same
     * customer. Only enable it for a tier that is billed by metered usage instead of
     * prepaid credit, and set a Stripe meter event name below.
     */
    'metered_ad_spend_enabled' => (bool) env('BILLING_METERED_AD_SPEND_ENABLED', false),

    // Stripe meter event name used by reportMeterEvent() when metered billing is enabled.
    'ad_spend_meter' => env('BILLING_AD_SPEND_METER'),

];
