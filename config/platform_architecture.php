<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform Integration Architecture Rules
    |--------------------------------------------------------------------------
    |
    | These rules codify the design patterns that MUST be followed when
    | integrating any new ad platform. All platforms use a management/MCC
    | account pattern — Spectra owns all ad accounts and manages spend
    | centrally. Customers never authenticate directly with ad platforms.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Authentication Model
    |--------------------------------------------------------------------------
    |
    | Every platform uses a single management-level credential owned by
    | Spectra. No per-customer OAuth flows. No customer-stored tokens.
    |
    */
    'auth_model' => 'management_account',

    'auth_rules' => [
        // Credentials are stored at the platform level, never on the Customer model
        'credential_storage'       => 'platform_level',

        // Token source priority: database table (encrypted) → .env fallback
        'token_source_priority'    => ['database_encrypted', 'env_fallback'],

        // Per-customer OAuth is explicitly prohibited
        'per_customer_oauth'       => false,

        // No customer-facing "Connect Account" UI flows
        'customer_facing_oauth_ui' => false,

        // No per-customer token refresh jobs
        'per_customer_token_refresh_jobs' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Model Fields
    |--------------------------------------------------------------------------
    |
    | Only account identifiers (IDs) should be stored on the Customer model.
    | Access tokens, refresh tokens, and expiry timestamps must NOT be stored
    | on the Customer model — they belong at the platform management level.
    |
    */
    'customer_fields' => [
        'allowed' => [
            '{platform}_customer_id',    // Sub-account customer ID
            '{platform}_account_id',     // Sub-account ad account ID
            '{platform}_campaign_id',    // For campaign tracking on Strategy model
        ],

        'prohibited' => [
            '{platform}_access_token',
            '{platform}_refresh_token',
            '{platform}_token_expires_at',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Services Per Platform
    |--------------------------------------------------------------------------
    |
    | Every platform integration must include at minimum these service classes.
    |
    */
    'required_services' => [
        'Base{Platform}Service',     // Auth + HTTP client (management credential only)
        'CampaignService',           // CRUD for campaigns
        'PerformanceService',        // Metrics fetch (impressions, clicks, cost, conversions)
    ],

    'recommended_services' => [
        'AdGroupService',            // Ad group / ad set management
        'ImportService',             // Cross-platform campaign import
        'AccountService',            // Sub-account creation under management account
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Agent Integration
    |--------------------------------------------------------------------------
    |
    | Every platform must be integrated into these cross-platform systems.
    |
    */
    'required_agent_integration' => [
        'ExecutionAgent',               // app/Services/Agents/{Platform}ExecutionAgent
        'DeploymentService',            // Platform routing in normalizePlatform()
        'MonitorCampaignStatus',        // Status monitoring job
        'FetchPerformanceData',         // Hourly metrics ingestion job
        'HealthCheckAgent',             // Proactive health monitoring
        'CampaignOptimizationAgent',    // Optimization metrics + recommendations
        'SelfHealingAgent',             // Autonomous issue repair
        'CreativeIntelligenceAgent',    // Creative performance analysis
        'AudienceIntelligenceAgent',    // Audience management
        'AdSpendBillingService',        // Spend tracking and billing
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform-Specific Configuration Template
    |--------------------------------------------------------------------------
    |
    | When creating config/{platform}.php, include these keys at minimum.
    |
    */
    'config_template' => [
        'client_id'          => 'env({PLATFORM}_CLIENT_ID)',
        'client_secret'      => 'env({PLATFORM}_CLIENT_SECRET)',
        'refresh_token'      => 'env({PLATFORM}_REFRESH_TOKEN)',
        'manager_account_id' => 'env({PLATFORM}_MANAGER_ACCOUNT_ID)',
        'environment'        => 'env({PLATFORM}_ENVIRONMENT, production)',
        'rate_limit'         => [
            'requests_per_minute' => 100,
            'retry_attempts'      => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered Platforms
    |--------------------------------------------------------------------------
    |
    | All active platform integrations and their auth patterns.
    |
    */
    'platforms' => [
        'google' => [
            'auth_type'           => 'mcc_oauth',
            'credential_storage'  => 'mcc_accounts_table',
            'management_entity'   => 'MCC (Manager Customer Center)',
            'sdk'                 => 'googleads/google-ads-php',
            'config'              => 'config/googleads.php',
            'services_namespace'  => 'App\\Services\\GoogleAds',
        ],

        'facebook' => [
            'auth_type'           => 'system_user_token',
            'credential_storage'  => 'env_only',
            'management_entity'   => 'Business Manager + System User',
            'sdk'                 => 'http_graph_api',
            'config'              => 'config/services.php (facebook key)',
            'services_namespace'  => 'App\\Services\\FacebookAds',
        ],

        'microsoft' => [
            'auth_type'           => 'management_oauth',
            'credential_storage'  => 'env',
            'management_entity'   => 'Manager Account',
            'sdk'                 => 'rest_json_v13',
            'config'              => 'config/microsoftads.php',
            'services_namespace'  => 'App\\Services\\MicrosoftAds',
        ],

        'linkedin' => [
            'auth_type'           => 'management_oauth',
            'credential_storage'  => 'env',
            'management_entity'   => 'Organization Ad Account',
            'sdk'                 => 'rest_versioned',
            'config'              => 'config/linkedinads.php',
            'services_namespace'  => 'App\\Services\\LinkedInAds',
        ],
    ],
];
