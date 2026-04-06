<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LinkedIn Ads API Configuration
    |--------------------------------------------------------------------------
    |
    | LinkedIn Marketing API credentials and settings.
    | https://learn.microsoft.com/en-us/linkedin/marketing/
    |
    */

    'client_id' => env('LINKEDIN_ADS_CLIENT_ID'),
    'client_secret' => env('LINKEDIN_ADS_CLIENT_SECRET'),
    'redirect_uri' => env('LINKEDIN_ADS_REDIRECT_URI'),
    'refresh_token' => env('LINKEDIN_ADS_REFRESH_TOKEN'),

    'api_version' => env('LINKEDIN_ADS_API_VERSION', '202404'),

    'rate_limit' => [
        'requests_per_day' => 100000,
        'retry_attempts' => 3,
        'retry_delay_ms' => 1000,
    ],

    'defaults' => [
        'currency' => 'USD',
        'locale' => 'en_US',
        'status' => 'PAUSED', // Start campaigns paused by default
    ],
];
