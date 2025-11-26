<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Campaign Testing Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, all campaigns will be created as PAUSED/draft status
    | regardless of the intended status. This is useful for testing
    | campaign creation without actually spending money.
    |
    | This setting is stored in the database and controlled via Admin Settings.
    | The config value here is just a fallback default.
    |
    */
    'testing_mode_default' => env('CAMPAIGN_TESTING_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Default Campaign Status
    |--------------------------------------------------------------------------
    |
    | The default status for newly created campaigns when not in testing mode.
    | Options: 'ENABLED' (active), 'PAUSED' (draft)
    |
    | In testing mode, campaigns will always be created as PAUSED.
    |
    */
    'default_status' => env('CAMPAIGN_DEFAULT_STATUS', 'ENABLED'),

    /*
    |--------------------------------------------------------------------------
    | Google Ads Campaign Statuses
    |--------------------------------------------------------------------------
    */
    'google_ads' => [
        'enabled' => 'ENABLED',
        'paused' => 'PAUSED',
        'removed' => 'REMOVED',
    ],

    /*
    |--------------------------------------------------------------------------
    | Facebook Ads Campaign Statuses
    |--------------------------------------------------------------------------
    */
    'facebook_ads' => [
        'enabled' => 'ACTIVE',
        'paused' => 'PAUSED',
    ],
];
