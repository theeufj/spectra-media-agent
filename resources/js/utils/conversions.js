const AW_ID = 'AW-18115663500';

/**
 * Central registry of client-side conversion events.
 * label: null = conversion action not yet created in Google Ads (safe no-op).
 *
 * To activate a conversion:
 *   1. Create the action in Google Ads UI
 *   2. Paste the label from the tag snippet here
 */
const CONVERSIONS = {
    signup:           { label: 'JPlcCMyP26YcEIytnL5D', value: 99,  currency: 'USD' },
    pricing_visit:    { label: null,                    value: 5,   currency: 'USD' },
    sandbox_launched: { label: null,                    value: 35,  currency: 'USD' },
};

/**
 * Fire a Google Ads conversion event by name.
 * Silently no-ops if gtag is not loaded or the label hasn't been set yet.
 */
export function trackConversion(event) {
    const def = CONVERSIONS[event];
    if (!def?.label || typeof gtag !== 'function') return;
    gtag('event', 'conversion', {
        send_to: `${AW_ID}/${def.label}`,
        value: def.value,
        currency: def.currency,
    });
}
