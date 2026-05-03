<?php

return [

    'bidding_strategy' => [
        'thresholds' => [
            'MAXIMIZE_CONVERSIONS' => 30,
            'MANUAL_CPC'           => 30,
            'ENHANCED_CPC'         => 50,
            'TARGET_CPA'           => 100,
        ],
        'cpa_target_multiplier'  => 0.9,
        'roas_target_multiplier' => 0.9,
    ],

    'bid_adjustment' => [
        'device_min_conversions' => 50,
        'device_min_clicks'      => 200,
        'device_divergence'      => 0.20,
        'hour_min_clicks'        => 30,
        'hour_high_threshold'    => 0.70,
        'hour_low_threshold'     => 1.50,
        'modifier_min'           => 0.10,
        'modifier_max'           => 10.0,
    ],

    'search_terms' => [
        'min_impressions'          => 300,
        'min_clicks'               => 5,
        'promote_ctr_threshold'    => 0.05,
        'negative_cost_threshold'  => 50.00,
        'negative_ctr_threshold'   => 0.002,
        'negative_min_impressions' => 500,
        'negative_match_type'      => 'PHRASE',
    ],

    'quality_score' => [
        'pause_qs_threshold' => 5,
        'pause_min_days'     => 21,
    ],

    'budget_intelligence' => [
        'reallocation_min_conversions' => 15,
        'reallocation_roas_ratio'      => 2.0,
        'reallocation_max_shift_pct'   => 0.10,
    ],

    'health_check' => [
        'performance_drop_threshold'   => 0.30,
        'performance_spike_threshold'  => 2.0,
        'creative_fatigue_impressions' => 10000,
        'creative_fatigue_ctr_drop'    => 0.25,
        'anomaly_min_impressions'      => 1000,
        'token_expiry_warning_days'    => 7,
    ],

    'campaign_optimization' => [
        'auto_apply_confidence'   => 0.95,
        'review_confidence'       => 0.70,
        'min_impressions_for_bid' => 1000,
        'min_clicks_for_ctr'      => 100,
        'min_conversions_for_cpa' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anomaly Detection
    |--------------------------------------------------------------------------
    | Fallback values used when a campaign has fewer than min_history_days of
    | data.  AdaptiveThresholds computes per-campaign values from historical
    | variance and clamps them to the [min, max] bounds below.
    |
    | Threshold semantics:
    |   ctr_drop   — fraction drop from same-weekday baseline that triggers alert
    |   cpc_spike  — fraction rise from baseline that triggers alert
    |   cvr_drop   — fraction drop from baseline that triggers alert
    |   regression — how far actual can exceed the Smart Bidding target before
    |                the agent reverts to the prior strategy
    |   budget_cut — how much to reduce daily budget in the auto-response
    */
    'anomaly_detection' => [
        'ctr_drop_min'     => 0.15,  // never alert on drops smaller than this
        'ctr_drop_max'     => 0.50,  // never require drops larger than this
        'ctr_drop_default' => 0.25,

        'cpc_spike_min'     => 0.30,
        'cpc_spike_max'     => 1.00,
        'cpc_spike_default' => 0.50,

        'cvr_drop_min'     => 0.20,
        'cvr_drop_max'     => 0.50,
        'cvr_drop_default' => 0.30,

        'min_impressions'   => 100,  // absolute floor regardless of campaign volume
        'min_clicks_cpc'    => 10,
        'min_clicks_cvr'    => 20,
        'min_history_days'  => 7,    // days of data needed before baselines are trusted

        'regression_tolerance_min' => 0.15,
        'regression_tolerance_max' => 0.40,

        'budget_cut_cpc' => 0.20,
        'budget_cut_cvr' => 0.25,
    ],

];
