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
    | Chrome Arguments
    |--------------------------------------------------------------------------
    |
    | Additional arguments to pass to the Chrome instance. The --no-sandbox 
    | argument is often required in headless environments like Linux servers.
    |
    */
    'chrome_args' => [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
    ],
];
