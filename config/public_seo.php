<?php

return [
    // A vertical can reuse the application without publishing another copy of
    // the same guide or product page as an independent search result.
    'shared_host' => 'sitetospend.com',

    'tenant_specific_paths' => ['/', '/pricing', '/how-it-works'],

    // Source paths supply the date of the last committed content change. Do
    // not use deployment time or filemtime: a release copies unchanged files.
    'pages' => [
        '/' => ['resources/js/Pages/Landing.jsx', 'resources/js/Pages/RealEstateLanding.jsx', 'config/faqs.php', 'app/Support/PublicMarketingContent.php', 'resources/views/public-content.blade.php'],
        '/features' => ['resources/js/Pages/Features.jsx', 'app/Support/PublicMarketingContent.php', 'resources/views/public-content.blade.php'],
        '/how-it-works' => ['resources/js/Pages/HowItWorks.jsx', 'resources/js/Pages/RealEstateHowItWorks.jsx', 'app/Support/PublicMarketingContent.php', 'resources/views/public-content.blade.php'],
        '/pricing' => ['resources/js/Pages/Pricing.jsx', 'resources/js/Pages/RealEstatePricing.jsx', 'config/faqs.php', 'app/Support/PublicMarketingContent.php', 'resources/views/public-content.blade.php'],
        '/about' => ['resources/js/Pages/About.jsx', 'app/Support/PublicMarketingContent.php', 'resources/views/public-content.blade.php'],
        '/google-ads-management' => ['app/Support/MarketingPages.php', 'resources/views/public-content.blade.php'],
        '/ai-ads-management' => ['app/Support/MarketingPages.php', 'resources/views/public-content.blade.php'],
        '/google-ads-setup' => ['app/Support/MarketingPages.php', 'resources/views/public-content.blade.php'],
        '/blog' => ['app/Support/HelpArticles.php', 'resources/js/Pages/Blog/Index.jsx', 'resources/views/public-content.blade.php'],
        '/terms-of-service' => ['resources/legal/terms.html', 'resources/views/public-content.blade.php'],
        '/privacy-policy' => ['resources/legal/privacy.html', 'resources/views/public-content.blade.php'],
    ],
];
