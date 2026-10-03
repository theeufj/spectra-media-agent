/**
 * Optional website attribution pixel. Install from the SiteToSpend attribution
 * page on the registered website, after any consent required by that website.
 * The site ID is public; there is no secret in browser code.
 */
(function () {
    'use strict';

    var script = document.currentScript || document.querySelector('script[src*="/js/spectra-pixel.js"][data-site-id]');
    if (!script) return;

    var siteId = script.getAttribute('data-site-id');
    if (!siteId || !/^[a-f0-9-]{36}$/i.test(siteId)) return;

    var endpoint = new URL('/api/tracking/', script.src).href;
    var cookieName = '_sts_vid_' + siteId;

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        if (!window.crypto || !window.crypto.getRandomValues) return null;
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.from(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    function cookie(name) {
        var parts = document.cookie.split(';');
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i].trim();
            if (part.indexOf(name + '=') === 0) return part.slice(name.length + 1);
        }
        return null;
    }

    var visitorId = cookie(cookieName);
    if (!visitorId || !/^[a-f0-9-]{36}$/i.test(visitorId)) {
        visitorId = uuid();
        if (!visitorId) return;
        var expires = new Date(Date.now() + 90 * 86400000).toUTCString();
        document.cookie = cookieName + '=' + visitorId + '; expires=' + expires + '; path=/; SameSite=Lax; Secure';
    }

    function safeUrl(value) {
        if (!value) return '';
        try {
            var url = new URL(value);
            return url.origin + url.pathname;
        } catch (e) {
            return '';
        }
    }

    function send(kind, values) {
        var body = new URLSearchParams({
            site_id: siteId,
            visitor_id: visitorId,
            page_url: safeUrl(window.location.href)
        });
        Object.keys(values).forEach(function (key) {
            if (values[key] !== null && values[key] !== undefined) body.set(key, String(values[key]));
        });

        // URLSearchParams is a simple form request: no CORS preflight or public
        // HMAC key is needed. The host site's Origin is checked by the server.
        if (navigator.sendBeacon && navigator.sendBeacon(endpoint + kind, body)) return;
        fetch(endpoint + kind, { method: 'POST', mode: 'no-cors', credentials: 'omit', keepalive: true, body: body }).catch(function () {});
    }

    var params = new URLSearchParams(window.location.search);
    var source = params.get('utm_source');
    var medium = params.get('utm_medium');
    var clickId = params.get('gclid') || params.get('gbraid') || params.get('wbraid');
    if (!source && clickId) source = 'google';
    if (!medium && clickId) medium = 'cpc';

    var sessionKey = '_sts_seen_' + siteId;
    var alreadySeen = false;
    try { alreadySeen = window.sessionStorage.getItem(sessionKey) === '1'; } catch (e) {}

    if (source || medium || params.get('utm_campaign') || !alreadySeen) {
        send('touchpoint', {
            utm_source: source,
            utm_medium: medium,
            utm_campaign: params.get('utm_campaign'),
            utm_content: params.get('utm_content'),
            utm_term: params.get('utm_term'),
            referrer: safeUrl(document.referrer)
        });
        try { window.sessionStorage.setItem(sessionKey, '1'); } catch (e) {}
    }

    window.SpectraPixel = {
        trackConversion: function (type, value) {
            send('conversion', {
                event_id: uuid(),
                conversion_type: type || 'lead',
                conversion_value: value === undefined ? 0 : value
            });
        }
    };
})();
