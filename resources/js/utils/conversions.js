const AW_ID = 'AW-16797144138';

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
 * Also logs the event server-side so we can monitor it in the admin dashboard.
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
    // Fire-and-forget server log so admin can monitor conversion counts.
    fetch('/spectra/conversion', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
        body:    JSON.stringify({ event }),
    }).catch(() => {});
}
