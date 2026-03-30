<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>Site to Spend - Agentic Digital Marketing</title>

        <!-- Favicons -->
        <link rel="icon" type="image/x-icon" href="/favicon.ico">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
        <link rel="icon" type="image/png" sizes="512x512" href="/favicon-512.png">

        <!-- Canonical URL -->
        <link rel="canonical" href="{{ str_replace('http://', 'https://', url()->current()) }}" />

        <!-- SEO Meta Tags -->
        <meta name="description" content="Site to Spend offers agentic digital marketing services, leveraging AI to create and manage ad campaigns across platforms like Google, Facebook, and more.">
        <meta name="keywords" content="AI marketing, digital marketing, ad campaign management, Google Ads, Facebook Ads, automated advertising, agentic marketing">
        <meta name="author" content="Site to Spend">

        <!-- Open Graph Meta Tags (for social sharing) -->
        <meta property="og:title" content="Site to Spend - Agentic Digital Marketing">
        <meta property="og:description" content="Automated ad campaigns powered by AI. Site to Spend creates, manages, and optimizes your digital advertising across all major platforms.">
        <meta property="og:image" content="{{ url('/og-image.png') }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:type" content="website">

        <!-- Twitter Card Meta Tags -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Site to Spend - Agentic Digital Marketing">
        <meta name="twitter:description" content="Leverage the power of AI for your ad campaigns. Site to Spend offers a fully autonomous digital marketing solution.">
        <meta name="twitter:image" content="{{ url('/twitter-image.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead

        <!-- Google Tag Manager -->
            <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','GTM-KQZW2JKF');</script>
        <!-- End Google Tag Manager -->
    </head>
    <body class="font-sans antialiased">
        <!-- Google Tag Manager (noscript) -->
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-KQZW2JKF"
            height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        @inertia
    </body>
</html>
