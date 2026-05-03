const AW_ID = 'AW-18115663500';

const CONVERSION_VALUES = {
    signup:           { value: 99,  currency: 'USD' },
    pricing_visit:    { value: 5,   currency: 'USD' },
    sandbox_launched: { value: 35,  currency: 'USD' },
};

// Labels are populated at app startup via initConversions() from Inertia shared props.
// They are provisioned server-side by `php artisan conversions:provision`.
let _labels = {};

/**
 * Called once in app.jsx with the conversionLabels shared prop.
 * After this, trackConversion() will fire for any event whose label is set.
 */
export function initConversions(labels) {
    if (labels && typeof labels === 'object') {
        _labels = labels;
    }
}

/**
 * Fire a Google Ads conversion event by name.
 * Safe no-op if gtag is not loaded or the label has not been provisioned yet.
 */
export function trackConversion(event) {
    const label = _labels[event];
    const def   = CONVERSION_VALUES[event];
    if (!label || !def || typeof gtag !== 'function') return;
    gtag('event', 'conversion', {
        send_to:  `${AW_ID}/${label}`,
        value:    def.value,
        currency: def.currency,
    });
}
