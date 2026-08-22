<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Node Binary Path
    |--------------------------------------------------------------------------
    |
    | The path to the Node.js executable. This is required by Browsershot
    | to run Puppeteer. We pull this from the .env file to allow for
    | different paths in different environments (e.g., local vs. production).
    |
    */
    'node_binary_path' => env('NODE_BINARY_PATH', '/usr/bin/node'),

    /*
    |--------------------------------------------------------------------------
    | Page render timeout
    |--------------------------------------------------------------------------
    |
    | Seconds Browsershot waits for a page before giving up. Thirty was too
    | short for JS-heavy storefronts: a re-crawl of two Shopify sites timed out
    | on ten pages and rendered one. The queue job allows 300 seconds, so there
    | is room, and a page that renders slowly is still worth having — the
    | alternative is a knowledge base missing every product page on the site.
    |
    */

    'page_timeout' => (int) env('BROWSERSHOT_PAGE_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Chrome Arguments
    |--------------------------------------------------------------------------
    |
    | Additional arguments to pass to the Chrome instance. The --no-sandbox
    | argument is often required in headless environments like Linux servers.
    |
    */
    'chrome_args' => [
        'no-sandbox',
        'disable-setuid-sandbox',
        'disable-dev-shm-usage',

        // We are reading text, not taking screenshots. Product pages on a
        // large storefront were exceeding a ninety-second render budget, and
        // almost all of that work is fetching and decoding assets that get
        // thrown away the moment the DOM is turned into a string. Chromium
        // renders roughly an order of magnitude faster without them, and five
        // of these run concurrently on one box.
        'blink-settings=imagesEnabled=false',
        'disable-remote-fonts',
        'mute-audio',
        'disable-extensions',
        'disable-background-networking',
        'disable-sync',
        'disable-translate',
    ],
];
