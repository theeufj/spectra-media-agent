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

    'page_timeout' => (int) env('BROWSERSHOT_PAGE_TIMEOUT', 90),

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
    ],
];
