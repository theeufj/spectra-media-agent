<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic first campaign
    |--------------------------------------------------------------------------
    |
    | After a successful sitemap crawl, build the customer's first campaign for
    | them and email them to review it. Set FIRST_CAMPAIGN_ENABLED=false to stop
    | it without a deploy: it spends model budget on every qualifying signup,
    | and it writes a campaign into an account unprompted, so it should be
    | switchable off from the environment.
    |
    */

    'enabled' => env('FIRST_CAMPAIGN_ENABLED', true),

];
