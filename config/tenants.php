<?php

return [

    'default' => env('TENANT_OVERRIDE', 'sitetospend.com'),

    'sitetospend.com' => [
        'key'             => 'sitetospend',
        'name'            => 'Site to Spend',
        'tagline'         => 'Your AI Marketing Team',
        'vertical'        => null,
        'locked_vertical' => false,
        'colors'          => [
            'primary'  => '#ff4d00',
            'dark'     => '#cc3d00',
            'darker'   => '#992e00',
            'accent'   => '#ffc300',
        ],
        'email_from'  => env('MAIL_FROM_ADDRESS', 'hello@sitetospend.com'),
        'logo_text'   => 'sitetospend',
        'logo_url'    => null,
    ],

    'realpropertyads.com' => [
        'key'             => 'realpropertyads',
        'name'            => 'Real Property Ads',
        'tagline'         => 'Ad campaigns built for real estate agents',
        'vertical'        => 'real_estate',
        'locked_vertical' => true,
        'colors'          => [
            'primary'  => '#1B3C6B',
            'dark'     => '#122A4E',
            'darker'   => '#0A1C35',
            'accent'   => '#C9A660',
        ],
        'email_from'  => 'hello@realpropertyads.com',
        'logo_text'   => 'Real Property Ads',
        'logo_url'    => null,
    ],

];
