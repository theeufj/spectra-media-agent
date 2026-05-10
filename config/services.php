<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
        'gemini_api_key' => env('GOOGLE_GEMINI_API_KEY', env('GEMINI_API_KEY')),
        'project_id' => env('GOOGLE_CLOUD_PROJECT'),
        'location' => env('GOOGLE_CLOUD_LOCATION', 'us-central1'),
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'), // Service account JSON key path for production
        // Custom Search API for competitor discovery
        'search_api_key' => env('GOOGLE_SEARCH_API_KEY'),
        'search_engine_id' => env('GOOGLE_SEARCH_ENGINE_ID'),
    ],

    // Platform-managed Google Tag Manager (service account — no per-user OAuth needed)
    'gtm' => [
        // Refresh token for the PLATFORM's Google account with GTM edit+publish scopes.
        // Generate once via: php artisan gtm:generate-token
        'platform_refresh_token' => env('GTM_PLATFORM_REFRESH_TOKEN'),
        // The GTM Account ID under which per-customer containers are created.
        'platform_account_id' => env('GTM_PLATFORM_ACCOUNT_ID'),
    ],

    'facebook' => [
        'client_id'     => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect'      => env('FACEBOOK_REDIRECT_URI', '/auth/facebook/callback'),
        'config_id'     => env('FACEBOOK_CONFIG_ID'),

        // Platform Business Manager (Path A — zero OAuth for new clients)
        // Create at business.facebook.com → System Users → Generate Token
        'business_manager_id' => env('FACEBOOK_BUSINESS_MANAGER_ID'),
        'system_user_token'   => env('FACEBOOK_SYSTEM_USER_TOKEN'),
        // Platform Facebook Page used as the ad publisher for all customer campaigns.
        // Customers do NOT need their own Facebook Page.
        'page_id'             => env('FACEBOOK_PAGE_ID'),
        // Spectra's own Meta Pixel ID for tracking own-site conversions (sitetospend.com).
        // Set this to the pixel ID from Spectra's BM that fires on sitetospend.com.
        'spectra_pixel_id'    => env('FACEBOOK_SPECTRA_PIXEL_ID'),
    ],

    'stripe' => [
        'model' => App\Models\User::class,
        'key' => env('STRIPE_PUBLISHABLE_KEY', env('STRIPE_KEY')),
        'secret' => env('STRIPE_SECRET_KEY', env('STRIPE_SECRET')),
        'webhook' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
        'ad_spend_price_id' => env('STRIPE_AD_SPEND_PRICE_ID'),
    ],

    'cloudflare' => [
        'turnstile_site_key' => env('CLOUDFLARE_TURNSTILE_SITE_KEY'),
        'turnstile_secret_key' => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
    ],

    'firecrawl' => [
        'api_key' => env('FIRECRAWL_API_KEY'),
    ],

    'moz' => [
        'api_key' => env('MOZ_API_KEY'),
    ],

];
