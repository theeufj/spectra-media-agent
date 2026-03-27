<?php

return [
    'path' => storage_path('app/google_ads_php.ini'),
    'use_test_account' => env('GOOGLE_ADS_USE_TEST_ACCOUNT', false),

    // Platform MCC account that manages all customer sub-accounts.
    // All campaigns are deployed under sub-accounts created within this MCC.
    'mcc_customer_id' => env('GOOGLE_ADS_MCC_CUSTOMER_ID'),
    'mcc_refresh_token' => env('GOOGLE_ADS_MCC_REFRESH_TOKEN'),
];
