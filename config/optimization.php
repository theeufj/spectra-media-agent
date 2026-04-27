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

];
