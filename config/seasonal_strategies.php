<?php

return [
    'black_friday' => [
        'description' => "Aggressive promotion for Black Friday. Focus on conversions and high-intent audiences. Increase budgets and use strong calls to action.",
        'budget_multiplier' => 1.5, // Increase budget by 50%
        'bidding_strategy' => 'TARGET_CPA',
        'keywords_to_add' => ['Black Friday deals', 'cyber monday sales'],
        'ad_copy_themes' => ['Urgency (limited time)', 'Discounts (e.g., 50% off)', 'Exclusive offers'],
    ],
    'summer_sale' => [
        'description' => "Mid-year sale to clear inventory and attract new customers. Focus on broader audiences and brand awareness.",
        'budget_multiplier' => 1.2, // Increase budget by 20%
        'bidding_strategy' => 'MAXIMIZE_CONVERSIONS',
        'keywords_to_add' => ['summer sale', 'seasonal discounts'],
        'ad_copy_themes' => ['New arrivals', 'Summer collection', 'Limited-time summer offers'],
    ],
    'default' => [
        'description' => "Standard, evergreen strategy for periods without specific seasonal events.",
        'budget_multiplier' => 1.0,
        'bidding_strategy' => 'MAXIMIZE_CONVERSIONS',
        'keywords_to_add' => [],
        'ad_copy_themes' => ['Evergreen benefits', 'Brand value proposition'],
    ],
];
