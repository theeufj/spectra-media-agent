<?php

return [
    'path' => storage_path('app/google_ads_php.ini'),
    'use_test_account' => env('GOOGLE_ADS_USE_TEST_ACCOUNT', false),
    'mcc_customer_id' => env('GOOGLE_ADS_MCC_CUSTOMER_ID'),
];
