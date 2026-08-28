<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- Favicons -->
        <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=2">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png?v=2">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png?v=2">
        <link rel="icon" type="image/png" sizes="512x512" href="/favicon-512.png?v=2">

        {{--
            Per-page metadata, rendered server-side.

            The React pages already set <Head> tags, but Inertia applies those in
            the browser. Google runs JavaScript so it eventually sees them;
            Facebook, LinkedIn, Slack and X do not, so every shared link showed
            the same generic title and description no matter which page it
            pointed at — and the server HTML carried no <title> at all.

            Controllers pass a `meta` prop; anything they omit falls back to the
            site defaults below.
        --}}
        @php
            $meta = data_get($page ?? [], 'props.meta', []);
            // Defaults speak the tenant's own brand — a realpropertyads.com
            // page must not fall back to a sitetospend title.
            $tenantCfg = request()->attributes->get('tenant', config('tenants.'.config('tenants.default'), []));
            $tenantBrand = ($tenantCfg['logo_text'] ?? 'sitetospend').' — '.($tenantCfg['tagline'] ?? 'AI-Powered Ad Campaign Management');
            $metaTitle = $meta['title'] ?? $tenantBrand;
            $metaDescription = $meta['description'] ?? 'AI-powered ad campaign management across Google Ads, Facebook Ads, Microsoft Ads, and LinkedIn. 6 autonomous agents optimize your campaigns 24/7.';
            $metaCanonical = $meta['canonical'] ?? str_replace('http://', 'https://', url()->current());
            $metaType = $meta['type'] ?? 'website';
        @endphp

        {{--
            One title element, not two. A second hard-coded <title inertia> used to
            sit above the favicons, so every page shipped two of them: browsers and
            crawlers take the first, which meant the per-page title below was never
            the one that counted. The `inertia` attribute stays here so client-side
            navigation still updates it.
        --}}
        <title inertia>{{ $metaTitle }}</title>

        <!-- Canonical URL -->
        <link rel="canonical" href="{{ $metaCanonical }}" />

        <!-- SEO Meta Tags -->
        <meta name="description" content="{{ $metaDescription }}">
        <meta name="keywords" content="AI ad management, AI marketing platform, Google Ads automation, Facebook Ads AI, automated ad campaigns, digital advertising AI, campaign optimization, ad spend management">
        <meta name="author" content="sitetospend">

        <!-- Open Graph Meta Tags (for social sharing) -->
        <meta property="og:title" content="{{ $meta['og_title'] ?? $metaTitle }}">
        <meta property="og:description" content="{{ $meta['og_description'] ?? $metaDescription }}">
        <meta property="og:image" content="{{ url('/og-image.png?v=2') }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:type" content="{{ $metaType }}">
        <meta property="og:site_name" content="sitetospend">

        <!-- Twitter Card Meta Tags -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $meta['og_title'] ?? $metaTitle }}">
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

        <!-- Google Ads tag (ensures window.gtag is always available for conversion tracking) -->
        {{-- AW-18115663500 owns every conversion action in this account — all seven,
             verified against conversion_action.tag_snippets. AW-16797144138 owned
             none, and loading it here is what made it look like the conversion
             account: utils/conversions.js hardcoded it and Google silently
             discarded every hit, because a label paired with an account that does
             not own it fails without an error. --}}
        <script async src="https://www.googletagmanager.com/gtag/js?id=AW-18115663500"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'AW-18115663500');
            gtag('config', 'G-WKHRP9NJPD');
        </script>
        <!-- End Google Ads tag -->

        <!-- Google Tag Manager (existing) -->
            <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','GTM-KHFLQZ8S');</script>
        <!-- End Google Tag Manager -->

        <!-- Google Tag Manager (Spectra — conversion tracking) -->
            <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','GTM-NF47M2K8');</script>
        <!-- End Google Tag Manager (Spectra) -->

@if(config('microsoftads.uet_tag_id'))
        <!-- Microsoft Ads UET Tag -->
        <script>(function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){var o={ti:"{{ config('microsoftads.uet_tag_id') }}"};o.q=w[u],w[u]=new UET(o),w[u].push("pageLoad")},n=d.createElement(t),n.src=r,n.async=1,n.onload=n.onreadystatechange=function(){var s=this.readyState;s&&s!=="loaded"&&s!=="complete"||(f(),n.onload=n.onreadystatechange=null)},i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)})(window,document,"script","//bat.bing.com/bat.js","uetq");</script>
        {{-- alt="" marks this as decorative: it is a tracking pixel, not content. --}}
        <noscript><img src="//bat.bing.com/action/0?ti={{ config('microsoftads.uet_tag_id') }}&Ver=2" height="0" width="0" alt="" style="display:none; visibility:hidden;" /></noscript>
        <!-- End Microsoft Ads UET Tag -->
        @endif
    </head>
    <body class="font-sans antialiased">
        <!-- Google Tag Manager (noscript) -->
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-KHFLQZ8S"
            height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        <!-- Google Tag Manager (noscript — Spectra) -->
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NF47M2K8"
            height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript — Spectra) -->
        @inertia
    </body>
</html>
