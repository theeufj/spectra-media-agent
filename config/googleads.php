<?php

return [
    'path' => storage_path('app/google_ads_php.ini'),
    'use_test_account' => env('GOOGLE_ADS_USE_TEST_ACCOUNT', false),

    // Platform MCC account that manages all customer sub-accounts.
    // All campaigns are deployed under sub-accounts created within this MCC.
    'mcc_customer_id' => env('GOOGLE_ADS_MCC_CUSTOMER_ID'),
    'mcc_refresh_token' => env('GOOGLE_ADS_MCC_REFRESH_TOKEN'),

    /*
     * Google will not let a fresh manager account create client accounts via the
     * API until a linked account has spent roughly this much, in USD, with a
     * clean policy history.
     */
    'account_creation_threshold_usd' => (float) env('GOOGLE_ADS_ACCOUNT_CREATION_THRESHOLD_USD', 1000.0),

    /*
     * Client accounts report cost in their own currency, so the eligibility check
     * has to convert before comparing against a USD threshold. These are static
     * rates, not live FX — the figure is context for a "not yet" message, never
     * the eligibility decision itself (Google's validate_only probe decides that).
     *
     * Deliberately rounded slightly low so the estimate errs towards "not yet"
     * rather than claiming eligibility we don't have. Override per-currency via
     * env if a rate drifts far enough to matter.
     */
    'usd_rates' => [
        'USD' => 1.0,
        'AUD' => (float) env('GOOGLE_ADS_USD_RATE_AUD', 0.70),
        'NZD' => (float) env('GOOGLE_ADS_USD_RATE_NZD', 0.60),
        'GBP' => (float) env('GOOGLE_ADS_USD_RATE_GBP', 1.27),
        'EUR' => (float) env('GOOGLE_ADS_USD_RATE_EUR', 1.08),
        'CAD' => (float) env('GOOGLE_ADS_USD_RATE_CAD', 0.73),
    ],
];
