<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages per site
    |--------------------------------------------------------------------------
    |
    | A soft ceiling on how much of one customer's site to crawl. Large stores
    | fan out enormously — one clothing site's sitemap index produced 15,326
    | queued pages, each a headless browser render and a paid embedding call,
    | which at a polite crawl rate is close to a day of work for content that
    | stops being informative long before the end.
    |
    | What the knowledge base is for is understanding the business: what it
    | sells, to whom, and why. Four hundred pages answers that for any site;
    | the nine hundredth product page does not add to it.
    |
    | Set to 0 to crawl everything.
    |
    */

    'max_pages_per_site' => (int) env('CRAWL_MAX_PAGES_PER_SITE', 400),

    /*
    |--------------------------------------------------------------------------
    | Share of the budget spent on product pages
    |--------------------------------------------------------------------------
    |
    | A store's product pages repeat the same few sentences with a different
    | noun: useful for the first handful, so the platform knows what is
    | actually sold, and noise after that. Its about, shipping, collection and
    | blog pages are what say who the business is and who it serves.
    |
    | Left unordered the budget fills with products and the about page is never
    | reached — one storefront offered 15,326 product URLs against roughly
    | thirty pages that described the business.
    |
    */

    'product_page_share' => (float) env('CRAWL_PRODUCT_PAGE_SHARE', 0.25),

];
