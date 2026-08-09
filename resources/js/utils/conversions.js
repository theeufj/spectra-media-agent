// Conversion targets are supplied by the server via the `conversionTargets`
// shared prop and initialised once in app.jsx.
//
// Each entry is a complete gtag target: { send_to, value, currency }, where
// send_to is "AW-XXXXXXXXX/label". The account id is deliberately NOT hardcoded
// here. A gtag conversion only counts when the account and label in send_to
// belong to each other; pairing a valid label with the wrong account fails
// silently — gtag returns no error and Google drops the conversion. This file
// previously hardcoded AW-16797144138 while every label belonged to
// AW-18115663500, so no client-side conversion ever reached Google.
let _targets = {};

/**
 * Called once in app.jsx with the conversionTargets shared prop.
 * After this, trackConversion() will fire for any provisioned event.
 */
export function initConversions(targets) {
    if (targets && typeof targets === 'object') {
        _targets = targets;
    }
}

/**
 * Fire a Google Ads conversion event by name.
 * Safe no-op if gtag is not loaded or the event has not been provisioned yet.
 * Also logs the event server-side so admin can monitor conversion counts.
 */
export function trackConversion(event) {
    const target = _targets[event];
    if (!target || !target.send_to) return;

    if (typeof gtag === 'function') {
        gtag('event', 'conversion', {
            send_to: target.send_to,
            value: target.value,
            currency: target.currency,
        });
    }

    // Logged even when gtag is blocked, so the admin dashboard reflects real
    // user behaviour rather than only ad-blocker-free sessions.
    fetch('/spectra/conversion', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ event }),
    }).catch(() => {});
}
