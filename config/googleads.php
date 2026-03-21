<?php

return [
    'path' => storage_path('app/google_ads_php.ini'),
    'use_test_account' => env('GOOGLE_ADS_USE_TEST_ACCOUNT', false),

    // MCC is no longer required. Users connect their own Google Ads accounts via OAuth
    // and manage their own billing directly. This is kept for backward compatibility only.
    'mcc_customer_id' => env('GOOGLE_ADS_MCC_CUSTOMER_ID'),
];
