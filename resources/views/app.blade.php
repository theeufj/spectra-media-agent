<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>Site to Spend - Agentic Digital Marketing</title>

        <!-- Favicons -->
        <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=2">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png?v=2">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png?v=2">
        <link rel="icon" type="image/png" sizes="512x512" href="/favicon-512.png?v=2">

        <!-- Canonical URL -->
        <link rel="canonical" href="{{ str_replace('http://', 'https://', url()->current()) }}" />

        <!-- SEO Meta Tags -->
        <meta name="description" content="AI-powered ad campaign management across Google Ads, Facebook Ads, Microsoft Ads, and LinkedIn. 6 autonomous agents optimize your campaigns 24/7.">
        <meta name="keywords" content="AI ad management, AI marketing platform, Google Ads automation, Facebook Ads AI, automated ad campaigns, digital advertising AI, campaign optimization, ad spend management">
        <meta name="author" content="sitetospend">

        <!-- Open Graph Meta Tags (for social sharing) -->
        <meta property="og:title" content="sitetospend — AI-Powered Ad Campaign Management">
        <meta property="og:description" content="6 autonomous AI agents create, manage, and optimize your digital ad campaigns across Google, Facebook, Microsoft, and LinkedIn 24/7.">
        <meta property="og:image" content="{{ url('/og-image.png?v=2') }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="sitetospend">

        <!-- Twitter Card Meta Tags -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="sitetospend — AI-Powered Ad Campaign Management">
        <meta name="twitter:description" content="6 autonomous AI agents create, manage, and optimize your digital ad campaigns across Google, Facebook, Microsoft, and LinkedIn.">
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
            })(window,document,'script','dataLayer','GTM-KHFLQZ8S');</script>
        <!-- End Google Tag Manager -->

        <!-- Google Ads (Spectra account AW-16797144138) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=AW-16797144138"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'AW-16797144138');
        </script>
        <!-- End Google Ads -->

        @if(config('microsoftads.uet_tag_id'))
        <!-- Microsoft Ads UET Tag -->
        <script>(function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){var o={ti:"{{ config('microsoftads.uet_tag_id') }}"};o.q=w[u],w[u]=new UET(o),w[u].push("pageLoad")},n=d.createElement(t),n.src=r,n.async=1,n.onload=n.onreadystatechange=function(){var s=this.readyState;s&&s!=="loaded"&&s!=="complete"||(f(),n.onload=n.onreadystatechange=null)},i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)})(window,document,"script","//bat.bing.com/bat.js","uetq");</script>
        <noscript><img src="//bat.bing.com/action/0?ti={{ config('microsoftads.uet_tag_id') }}&Ver=2" height="0" width="0" style="display:none; visibility:hidden;" /></noscript>
        <!-- End Microsoft Ads UET Tag -->
        @endif
    </head>
    <body class="font-sans antialiased">
        <!-- Google Tag Manager (noscript) -->
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-KHFLQZ8S"
            height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        @inertia
    </body>
</html>
