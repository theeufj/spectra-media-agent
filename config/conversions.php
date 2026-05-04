<?php

return [
    /*
     * The Google Ads account ID (numeric, no dashes) that owns the Spectra conversion actions.
     * This is sitetospend.com's own advertising account, separate from customer accounts.
     */
    'google_ads_customer_id' => env('SPECTRA_GOOGLE_ADS_CUSTOMER_ID', '3598653839'),

    'aw_id' => 'AW-18115663500',

    /*
     * Conversion event definitions.
     *
     * label         — Google Ads conversion label from the tag snippet (e.g. "JPlcCMyP26YcEIytnL5D").
     *                 Set to null until the conversion action is created in Google Ads.
     * value         — Estimated USD value of this micro-conversion.
     * currency      — ISO 4217 code.
     * mode          — 'client' fires via gtag in the browser.
     *                 'server' uploads via the Google Ads Conversions API (requires gclid on user).
     * resource_name — Google Ads resource name for server-side upload
     *                 (e.g. "customers/18115663500/conversionActions/XXXXX").
     *                 Only needed for 'server' mode events.
     *
     * To add a new conversion point:
     *   1. Create the conversion action in Google Ads UI.
     *   2. Copy the label from the tag snippet.
     *   3. Add an entry here.
     *   4. For client mode: call trackConversion('event_name') in the relevant React page/component.
     *   5. For server mode: dispatch RecordSiteConversion::dispatch($customer, 'event_name').
     */
    'events' => [
        'signup' => [
            'label'    => 'JPlcCMyP26YcEIytnL5D',
            'value'    => 99.00,
            'currency' => 'USD',
            'mode'     => 'client',
        ],
        'pricing_visit' => [
            'label'    => null,
            'value'    => 5.00,
            'currency' => 'USD',
            'mode'     => 'client',
        ],
        'sandbox_launched' => [
            'label'    => null,
            'value'    => 35.00,
            'currency' => 'USD',
            'mode'     => 'client',
        ],
        'campaign_live' => [
            'label'         => null,
            'value'         => 80.00,
            'currency'      => 'USD',
            'mode'          => 'server',
            'resource_name' => null,
        ],
        'seven_day_return' => [
            'label'         => null,
            'value'         => 50.00,
            'currency'      => 'USD',
            'mode'          => 'server',
            'resource_name' => null,
        ],
    ],
];
