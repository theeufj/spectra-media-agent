/**
 * Spectra Attribution Pixel
 *
 * Captures UTM parameters from ad clicks, stores in first-party cookie,
 * and reports touchpoints to the Spectra attribution API.
 *
 * Usage: Include via GTM or directly:
 *   <script src="/js/spectra-pixel.js" data-customer="CUSTOMER_ID"></script>
 */
(function () {
    'use strict';

    var COOKIE_NAME = '_spectra_attr';
    var COOKIE_DAYS = 90;
    var VISITOR_COOKIE = '_spectra_vid';
    var API_ENDPOINT = '/api/tracking/touchpoint';

    // Get customer ID from script tag
    var scripts = document.getElementsByTagName('script');
    var customerId = null;
    for (var i = 0; i < scripts.length; i++) {
        if (scripts[i].src && scripts[i].src.indexOf('spectra-pixel') !== -1) {
            customerId = scripts[i].getAttribute('data-customer');
            break;
        }
    }

    if (!customerId) return;

    /**
     * Parse URL query parameters
     */
    function getQueryParams() {
        var params = {};
        var search = window.location.search.substring(1);
        if (!search) return params;
        var pairs = search.split('&');
        for (var i = 0; i < pairs.length; i++) {
            var pair = pairs[i].split('=');
            if (pair.length === 2) {
                params[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1]);
            }
        }
        return params;
    }

    /**
     * Set a first-party cookie
     */
    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax; Secure';
    }

    /**
     * Read a cookie value
     */
    function getCookie(name) {
        var nameEQ = name + '=';
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i].trim();
            if (c.indexOf(nameEQ) === 0) {
                return decodeURIComponent(c.substring(nameEQ.length));
            }
        }
        return null;
    }

    /**
     * Generate a random visitor ID
     */
    function generateVisitorId() {
        var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        var id = '';
        for (var i = 0; i < 32; i++) {
            id += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return id;
    }

    /**
     * Get or create persistent visitor ID
     */
    function getVisitorId() {
        var vid = getCookie(VISITOR_COOKIE);
        if (!vid) {
            vid = generateVisitorId();
            setCookie(VISITOR_COOKIE, vid, 365);
        }
        return vid;
    }

    /**
     * Record touchpoint
     */
    function recordTouchpoint(utmParams) {
        var visitorId = getVisitorId();

        var touchpoint = {
            customer_id: customerId,
            visitor_id: visitorId,
            utm_source: utmParams.utm_source || null,
            utm_medium: utmParams.utm_medium || null,
            utm_campaign: utmParams.utm_campaign || null,
            utm_content: utmParams.utm_content || null,
            utm_term: utmParams.utm_term || null,
            page_url: window.location.href,
            referrer: document.referrer || null,
            timestamp: new Date().toISOString()
        };

        // Store in cookie for multi-page journeys
        var existing = getCookie(COOKIE_NAME);
        var touchpoints = existing ? JSON.parse(existing) : [];
        touchpoints.push(touchpoint);
        // Keep last 20 touchpoints
        if (touchpoints.length > 20) {
            touchpoints = touchpoints.slice(-20);
        }
        setCookie(COOKIE_NAME, JSON.stringify(touchpoints), COOKIE_DAYS);

        // Send to server
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', API_ENDPOINT, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.send(JSON.stringify(touchpoint));
        } catch (e) {
            // Silently fail — don't break the host page
        }
    }

    // Main: check for UTM parameters
    var params = getQueryParams();
    var hasUtm = params.utm_source || params.utm_medium || params.utm_campaign;

    if (hasUtm) {
        recordTouchpoint(params);
    }

    // Expose conversion tracking for the host page
    window.SpectraPixel = {
        trackConversion: function (conversionType, conversionValue) {
            var visitorId = getVisitorId();
            var existing = getCookie(COOKIE_NAME);
            var touchpoints = existing ? JSON.parse(existing) : [];

            try {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', API_ENDPOINT.replace('touchpoint', 'conversion'), true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.send(JSON.stringify({
                    customer_id: customerId,
                    visitor_id: visitorId,
                    conversion_type: conversionType || 'purchase',
                    conversion_value: conversionValue || 0,
                    touchpoints: touchpoints
                }));
            } catch (e) {
                // Silently fail
            }
        }
    };
})();
