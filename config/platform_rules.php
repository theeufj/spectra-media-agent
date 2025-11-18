<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform-Specific Validation Rules
    |--------------------------------------------------------------------------
    |
    | This file contains the programmatic validation rules for ad copy and other
    | collateral across different advertising platforms. Centralizing them here
    | makes them easy to manage and extend.
    |
    */

    'google' => [
        'headline_min_length' => 5,
        'headline_max_length' => 30,
        'headline_count' => 5,
        'description_min_length' => 10,
        'description_max_length' => 90,
        'description_count' => 3,
        'max_exclamations_per_element' => 1,
        'allow_consecutive_exclamations' => false,
    ],

    'google.sem' => [
        'headline_min_length' => 5,
        'headline_max_length' => 30,
        'headline_count' => 5,
        'description_min_length' => 10,
        'description_max_length' => 90,
        'description_count' => 3,
        'max_exclamations_per_element' => 1,
        'allow_consecutive_exclamations' => false,
    ],

    'facebook' => [
        'headline_min_length' => 5,
        'headline_max_length' => 40,
        'headline_count' => 3,
        'description_min_length' => 10,
        'description_max_length' => 125,
        'description_count' => 2,
        'max_exclamations_per_element' => 3,
        'allow_consecutive_exclamations' => true,
    ],

    'tiktok' => [
        'headline_min_length' => 5,
        'headline_max_length' => 30,
        'headline_count' => 5,
        'description_min_length' => 10,
        'description_max_length' => 90,
        'description_count' => 3,
        'max_exclamations_per_element' => 1,
        'allow_consecutive_exclamations' => false,
    ],

    'reddit' => [
        'headline_min_length' => 5,
        'headline_max_length' => 30,
        'headline_count' => 5,
        'description_min_length' => 10,
        'description_max_length' => 90,
        'description_count' => 3,
        'max_exclamations_per_element' => 1,
        'allow_consecutive_exclamations' => false,
    ],

    'microsoft' => [
        'headline_min_length' => 5,
        'headline_max_length' => 30,
        'headline_count' => 5,
        'description_min_length' => 10,
        'description_max_length' => 90,
        'description_count' => 3,
        'max_exclamations_per_element' => 1,
        'allow_consecutive_exclamations' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Content Validation Rules
    |--------------------------------------------------------------------------
    |
    | These rules are used by the AdminMonitorService to validate generated
    | content across all platforms.
    |
    */

    'negative_keywords' => [
        'N/A',
        'not applicable',
        'repurpose',
        'no image',
    ],

    'forbidden_keywords' => [
        'violence',
        'hate',
        'explicit',
        // Add more sensitive or off-brand keywords here
    ],
];
