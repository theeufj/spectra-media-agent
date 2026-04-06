<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Microsoft Advertising API Configuration
    |--------------------------------------------------------------------------
    */

    'client_id' => env('MICROSOFT_ADS_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_ADS_CLIENT_SECRET'),
    'tenant_id' => env('MICROSOFT_ADS_TENANT_ID', 'common'),
    'developer_token' => env('MICROSOFT_ADS_DEVELOPER_TOKEN'),
    'refresh_token' => env('MICROSOFT_ADS_REFRESH_TOKEN'),

    // MCC (Manager) account ID for managing sub-accounts
    'manager_account_id' => env('MICROSOFT_ADS_MANAGER_ACCOUNT_ID'),

    // API environment: production or sandbox
    'environment' => env('MICROSOFT_ADS_ENVIRONMENT', 'production'),

    // Rate limiting
    'rate_limit' => [
        'requests_per_minute' => 100,
        'retry_attempts' => 3,
        'retry_delay_ms' => 1000,
    ],

    // Campaign defaults
    'defaults' => [
        'time_zone' => 'EasternStandardTime',
        'country_code' => 'US',
        'language' => 'English',
    ],
];
