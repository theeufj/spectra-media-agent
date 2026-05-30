<?php

/**
 * Industry vertical templates.
 *
 * Each vertical defines the campaign structure, bidding strategy,
 * keyword themes, negative keywords, extension types, and minimum daily budget
 * used by GoogleAdsExecutionAgent when customer->industry matches a key.
 *
 * Keys should match the values stored in customers.industry.
 */
return [

    'ecommerce' => [
        'label'              => 'E-commerce',
        'campaign_type'      => 'SHOPPING',
        'fallback_type'      => 'SEARCH',
        'bidding_strategy'   => 'TARGET_ROAS',
        'target_roas'        => 400,          // 400% = 4x ROAS
        'min_daily_budget'   => 20.00,
        'keyword_themes'     => [
            'buy {product}',
            '{product} online',
            '{product} sale',
            'best {product}',
            '{product} price',
            '{product} deals',
            'free shipping {product}',
        ],
        'negative_keywords'  => [
            'free', 'diy', 'how to make', 'tutorial', 'reddit', 'review',
            'used', 'second hand', 'cheap', 'craigslist', 'ebay',
        ],
        'extensions'         => ['sitelink', 'callout', 'promotion', 'price'],
        'ad_rotation'        => 'OPTIMIZE',
        'network'            => ['search', 'shopping'],
    ],

    'local_services' => [
        'label'              => 'Local Services',
        'campaign_type'      => 'SEARCH',
        'bidding_strategy'   => 'TARGET_CPA',
        'target_cpa'         => 50.00,
        'min_daily_budget'   => 15.00,
        'location_radius_km' => 30,
        'keyword_themes'     => [
            '{service} near me',
            '{service} {city}',
            'local {service}',
            '{service} company',
            'best {service} in {city}',
            '{service} cost',
            'affordable {service}',
            'emergency {service}',
        ],
        'negative_keywords'  => [
            'jobs', 'career', 'salary', 'how to', 'diy', 'course',
            'training', 'school', 'certification', 'wiki',
        ],
        'extensions'         => ['call', 'location', 'sitelink', 'callout'],
        'ad_rotation'        => 'OPTIMIZE',
        'network'            => ['search'],
        'call_only'          => false,
        'use_local_services_ads' => true,
    ],

    'b2b_saas' => [
        'label'              => 'B2B SaaS',
        'campaign_type'      => 'SEARCH',
        'bidding_strategy'   => 'TARGET_CPA',
        'target_cpa'         => 200.00,
        'min_daily_budget'   => 30.00,
        'keyword_themes'     => [
            '{product} software',
            '{product} platform',
            '{product} tool',
            'best {product} software',
            '{product} for {industry}',
            '{product} pricing',
            '{product} alternative',
            '{competitor} alternative',
        ],
        'negative_keywords'  => [
            'free download', 'crack', 'torrent', 'open source', 'github',
            'student', 'personal', 'jobs', 'career', 'salary',
        ],
        'extensions'         => ['sitelink', 'callout', 'lead_form', 'structured_snippet'],
        'ad_rotation'        => 'OPTIMIZE',
        'network'            => ['search'],
        'linkedin_targeting' => [
            'job_functions'  => ['Information Technology', 'Operations', 'Finance'],
            'seniority'      => ['Director', 'VP', 'C-Level', 'Manager'],
            'company_sizes'  => ['51-200', '201-500', '501-1000', '1001-5000'],
        ],
    ],

    'real_estate' => [
        'label'              => 'Real Estate',
        'campaign_type'      => 'SEARCH',
        'bidding_strategy'   => 'TARGET_CPA',
        'target_cpa'         => 80.00,
        'min_daily_budget'   => 20.00,
        'keyword_themes'     => [
            // Property listing — buyer intent
            'homes for sale {suburb}',
            '{bedrooms} bedroom house for sale {suburb}',
            'property for sale {suburb}',
            'houses for sale {suburb}',
            'real estate {suburb}',
            'buy house {suburb}',
            'property listings {suburb}',
            // Seller intent
            'sell my house {suburb}',
            'real estate agent {suburb}',
            'house appraisal {suburb}',
            'free property appraisal {suburb}',
            'best real estate agent {suburb}',
            // Generic high-intent
            'properties for sale near me',
            'local real estate agent',
        ],
        'negative_keywords'  => [
            'rent', 'rental', 'apartment for rent', 'lease', 'tenant', 'landlord',
            'property management', 'how to become realtor', 'real estate license',
            'real estate course', 'real estate school', 'real estate investing course',
            'free', 'zillow', 'redfin', 'trulia', 'jobs', 'careers',
        ],
        'extensions'         => ['call', 'sitelink', 'callout', 'location'],
        'sitelink_suggestions' => [
            ['text' => 'View This Listing',    'description1' => 'See full property details',    'description2' => 'Photos, floor plan & price'],
            ['text' => 'Book an Inspection',   'description1' => 'Schedule a viewing today',     'description2' => 'Select a time that suits you'],
            ['text' => 'Contact the Agent',    'description1' => 'Speak to us directly',         'description2' => 'Quick response guaranteed'],
            ['text' => 'More Properties',      'description1' => 'Browse all listings',          'description2' => 'Find your perfect home'],
            ['text' => 'Free Appraisal',       'description1' => 'Thinking of selling?',         'description2' => 'Get a free market appraisal'],
            ['text' => 'Mortgage Calculator',  'description1' => 'Estimate your repayments',     'description2' => 'Know your borrowing power'],
        ],
        'callout_suggestions' => [
            'Licensed Real Estate Agent',
            'Local Market Experts',
            'Free Property Appraisal',
            'Open for Inspection',
            'Off-Market Properties Available',
            'Fast Response Guaranteed',
            'Trusted by Local Buyers',
            'No-Obligation Consultation',
        ],
        'ad_rotation'        => 'OPTIMIZE',
        'network'            => ['search', 'display'],
        'remarketing'        => true,
        'conversion_goal'    => 'Lead',
        'revenue_cpa_multiple' => 12.0, // property commissions are high relative to CPA
        'ad_copy_guidance'   => [
            'Include property address or suburb in at least one headline',
            'Mention bedroom count and key feature (e.g. "4 Bed Family Home – {Suburb}")',
            'Use urgency where appropriate ("Inspect This Weekend", "New to Market")',
            'CTA should drive direct enquiry: "Enquire Now", "Book Inspection", "Call Today"',
            'Avoid generic claims — be specific about the property or the agent\'s local expertise',
        ],
    ],

    'healthcare' => [
        'label'              => 'Healthcare',
        'campaign_type'      => 'SEARCH',
        'bidding_strategy'   => 'MAXIMIZE_CONVERSIONS',
        'min_daily_budget'   => 25.00,
        'keyword_themes'     => [
            '{treatment} near me',
            '{specialty} doctor {city}',
            'best {treatment} clinic',
            '{condition} treatment',
            'affordable {treatment}',
            '{specialty} appointment',
        ],
        'negative_keywords'  => [
            'jobs', 'nurse jobs', 'doctor salary', 'medical school',
            'how to treat', 'home remedy', 'symptoms', 'wikipedia',
        ],
        'extensions'         => ['call', 'sitelink', 'callout'],
        'ad_rotation'        => 'OPTIMIZE',
        'network'            => ['search'],
        'compliance_notes'   => [
            'No "guarantee" or "cure" claims in ad copy.',
            'Before/after imagery prohibited.',
            'Must include disclaimer for regulated treatments.',
            'No targeting of specific health conditions as audiences.',
        ],
        'restricted_terms'   => [
            'guarantee', 'cure', 'miracle', 'before and after',
            'lose weight fast', 'instant results',
        ],
    ],

];
