// Called only after a confirmed, consented business event. Observe the normal Google request without firing it a second time.
(function () {
    'use strict';
    const appOrigin = new URL(document.currentScript.src).origin;
    function watch({ goal, eventId, amount, currency, consent }) {
        const review = window.opener;
        if (!review || consent !== 'granted') return Promise.resolve(null);
        if (!['signup', 'purchase', 'lead'].includes(goal) || typeof eventId !== 'string' || !eventId || eventId.length > 160
            || (goal === 'purchase' && (!(Number(amount) > 0) || !/^[A-Z]{3}$/.test(currency)))) {
            return Promise.reject(new Error('Provide the confirmed business event ID and paid amount and currency for a purchase.'));
        }
        const started = Date.now();
        return new Promise((resolve, reject) => {
            let context;
            let receipt;
            const observer = new PerformanceObserver(list => scan(list.getEntries()));
            const timer = setTimeout(() => finish(new Error('The matching Google transaction could not be confirmed in the verification tab.')), 90000);
            function finish(error) {
                observer.disconnect();
                clearTimeout(timer);
                window.removeEventListener('message', receive);
                if (error) reject(error);
                else resolve(receipt);
            }
            function receive(event) {
                if (event.origin !== appOrigin || event.source !== review || !event.data || event.data.goal !== goal) return;
                if (event.data.type === 'sitetospend-test-context' && !context && typeof event.data.challenge === 'string'
                    && /^AW-\d+\/[A-Za-z0-9_-]+$/.test(event.data.send_to)) {
                    context = event.data;
                    observer.observe({entryTypes: ['resource']});
                    scan(performance.getEntriesByType('resource'));
                }
                if (receipt && event.data.type === 'sitetospend-test-response' && event.data.challenge === context.challenge && event.data.event_id === eventId) {
                    finish(event.data.accepted ? null : new Error('The verification tab declined the browser observation.'));
                }
            }
            function scan(entries) {
                if (!context || receipt) return;
                const [id, label] = context.send_to.slice(3).split('/');
                for (const entry of entries) {
                    const seen = Math.round(performance.timeOrigin + entry.startTime);
                    if (seen < started - 30000 || seen > Date.now() + 1000) continue;
                    const url = new URL(entry.name, location.href);
                    if (url.protocol !== 'https:' || !['www.googleadservices.com', 'www.google.com', 'googleads.g.doubleclick.net'].includes(url.hostname)) continue;
                    const route = /^\/pagead\/(?:1p-)?(?:viewthrough)?conversion\/(\d+)\/?$/.exec(url.pathname);
                    const args = url.searchParams;
                    if (!route || route[1] !== id || args.get('label') !== label || (args.get('oid') || args.get('transaction_id')) !== eventId) continue;
                    if (goal === 'purchase' && (Number(args.get('value')) !== Number(amount) || args.get('currency_code') !== currency)) continue;
                    receipt = { challenge: context.challenge, send_to: context.send_to, goal, event_id: eventId, observed_at: new Date(seen).toISOString() };
                    if (goal === 'purchase') Object.assign(receipt, { amount: String(amount), currency });
                    review.postMessage({type: 'sitetospend-test-observation', receipt}, appOrigin);
                    return;
                }
            }
            window.addEventListener('message', receive);
            review.postMessage({type: 'sitetospend-test-ready', goal}, appOrigin);
        });
    }
    window.SiteToSpendGoogleTest = Object.freeze({ watch });
}());
