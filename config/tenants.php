<?php

return [

    /*
     * The skin served when the request host matches no tenant below. Fixed —
     * it used to read TENANT_OVERRIDE, so setting that variable to preview one
     * vertical would have re-skinned every unrecognised host to it as well.
     */
    'default' => 'sitetospend.com',

    /*
     * Local development only: forces DetectTenant to resolve this host instead
     * of the real one. Null in production, where config:cache means env() is
     * never consulted at request time anyway.
     */
    'override' => env('TENANT_OVERRIDE'),

    /*
     * The Forge site's own hostname, which resolves to the same box as the
     * tenant domains and is how the app is reached before DNS is pointed.
     * A scalar, not an array, because everything that walks this file treats
     * an array value as a tenant skin (Tenant::config, TenantCheck).
     *
     * Listed here because bootstrap/app.php trusts exactly the tenant domains
     * plus this one and rejects every other Host header. Change it if the
     * Forge site is renamed, or this box stops answering on its own name.
     */
    'forge_domain' => 'spectra-media-agent-akedulbe.on-forge.com',

    'sitetospend.com' => [
        'key' => 'sitetospend',
        'name' => 'Site to Spend',
        'tagline' => 'Your AI Marketing Team',
        'vertical' => null,
        'locked_vertical' => false,
        'colors' => [
            'primary' => '#ff4d00',
            'dark' => '#cc3d00',
            'darker' => '#992e00',
            'accent' => '#ffc300',
        ],
        'email_from' => env('MAIL_FROM_ADDRESS', 'hello@sitetospend.com'),
        'logo_text' => 'sitetospend',
        'logo_url' => null,
    ],

    'realpropertyads.com' => [
        'key' => 'realpropertyads',
        'name' => 'Real Property Ads',
        'tagline' => 'Ad campaigns built for real estate agents',
        'vertical' => 'real_estate',
        'locked_vertical' => true,
        'colors' => [
            'primary' => '#1B3C6B',
            'dark' => '#122A4E',
            'darker' => '#0A1C35',
            'accent' => '#C9A660',
        ],
        'email_from' => 'hello@realpropertyads.com',
        'logo_text' => 'Real Property Ads',
        'logo_url' => null,
    ],

];
