<?php

/**
 * The public funnel's forecast preview.
 *
 * The demo used to end on a plan — ad copy and brand colours. These settings
 * drive the step that ends it on Google's own numbers for the visitor's market
 * instead: real search volume, real bids, and Google's forecast of what that
 * keyword set delivers.
 */
return [

    /*
     * Keyword Planner market for the forecast. Volume and bids are
     * location-specific, so a forecast run against the wrong country is worse
     * than no forecast at all.
     */
    'geo_target' => env('DEMO_GEO_TARGET', 'geoTargetConstants/2036'),  // Australia
    'language' => env('DEMO_LANGUAGE', 'languageConstants/1000'),       // English

    /*
     * How many keywords the forecast is built from. These are the visitor's
     * keywords, chosen by the same volume-over-competition score the real
     * campaign builder uses.
     */
    'keyword_count' => (int) env('DEMO_KEYWORD_COUNT', 10),

    'forecast_days' => (int) env('DEMO_FORECAST_DAYS', 30),

    /*
     * Google returns conversion figures only when given a rate to apply, so
     * this number is ours, not theirs. It is surfaced in the response and must
     * stay visible on the page: everything else in the forecast is Google's
     * measurement, and this one line is an assumption.
     */
    'conversion_rate' => (float) env('DEMO_CONVERSION_RATE', 0.03),

    /*
     * Bid percentile across the chosen keywords, between the low and high
     * top-of-page bids Google reports. 1.0 bids to the top of the page for
     * every keyword — honest for "what it costs to actually show up", and the
     * figure the forecast is then built on.
     */
    'bid_aggressiveness' => (float) env('DEMO_BID_AGGRESSIVENESS', 0.75),

];
