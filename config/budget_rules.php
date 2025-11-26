<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Time of Day Multipliers
    |--------------------------------------------------------------------------
    |
    | Bid/budget multipliers based on time of day. These are applied to adjust
    | campaign spending during different periods to maximize ROI.
    |
    */
    'time_of_day_multipliers' => [
        '00:00-06:00' => 0.5,  // Reduce overnight
        '06:00-09:00' => 1.2,  // Morning commute
        '09:00-17:00' => 1.0,  // Business hours
        '17:00-21:00' => 1.3,  // Evening prime time
        '21:00-00:00' => 0.8,  // Late night
    ],

    /*
    |--------------------------------------------------------------------------
    | Day of Week Multipliers
    |--------------------------------------------------------------------------
    |
    | Bid/budget multipliers based on day of week. Adjust spending to align
    | with typical conversion patterns throughout the week.
    |
    */
    'day_of_week_multipliers' => [
        'monday' => 1.0,
        'tuesday' => 1.1,
        'wednesday' => 1.1,
        'thursday' => 1.2,
        'friday' => 1.3,
        'saturday' => 0.9,
        'sunday' => 0.8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Seasonal Multipliers
    |--------------------------------------------------------------------------
    |
    | Special multipliers for key shopping dates. These override the day-of-week
    | multipliers when active.
    |
    */
    'seasonal_multipliers' => [
        // Black Friday (last Friday of November)
        'black_friday' => 2.0,
        // Cyber Monday
        'cyber_monday' => 1.8,
        // Christmas Eve
        '12-24' => 1.5,
        // Boxing Day
        '12-26' => 1.6,
        // New Year's Eve
        '12-31' => 1.3,
        // Valentine's Day
        '02-14' => 1.4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Reallocation Rules
    |--------------------------------------------------------------------------
    |
    | Rules for automatically reallocating budget between campaigns.
    |
    */
    'reallocation_rules' => [
        // Minimum ROAS before a campaign is considered "underperforming"
        'min_roas_threshold' => 1.5,
        
        // Maximum percentage of budget to shift in a single reallocation
        'max_shift_percentage' => 20,
        
        // Minimum days of data before reallocation is considered
        'min_data_days' => 7,
        
        // Minimum conversions before a campaign is eligible for reallocation
        'min_conversions' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Healing Rules
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic campaign repair actions.
    |
    */
    'self_healing' => [
        // Maximum number of auto-fix attempts per ad
        'max_fix_attempts' => 3,
        
        // Hours to wait before retrying a failed fix
        'retry_delay_hours' => 24,
        
        // Auto-pause ads after this many consecutive disapprovals
        'auto_pause_after_failures' => 2,
        
        // Automatically pause segments with CTR below this threshold
        'min_ctr_threshold' => 0.005, // 0.5%
        
        // Automatically pause segments with this cost and no conversions
        'max_spend_no_conversion' => 50.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Term Mining Rules
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic keyword discovery from search terms.
    |
    */
    'search_term_mining' => [
        // Minimum impressions before a search term is evaluated
        'min_impressions' => 100,
        
        // Minimum clicks before a search term is evaluated
        'min_clicks' => 5,
        
        // Add as exact match if CTR is above this and has conversions
        'promote_ctr_threshold' => 0.05, // 5%
        
        // Add as negative if cost is above this with no conversions
        'negative_cost_threshold' => 20.00,
        
        // Add as negative if CTR is below this with high impressions
        'negative_ctr_threshold' => 0.002, // 0.2%
        
        // Minimum impressions to consider for low-CTR negative
        'negative_min_impressions' => 500,
    ],
];
