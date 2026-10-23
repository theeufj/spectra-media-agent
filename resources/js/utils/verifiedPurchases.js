import { fetchJson } from '@/utils/http';

const invoked = new Set();
const configured = new Set();

/** An invocation of a server-receipted purchase is separate from Google delivery proof. */
export async function invokeVerifiedPurchases(receipts = []) {
    if (!Array.isArray(receipts) || receipts.length === 0 || typeof window.gtag !== 'function') return;
    let current;
    try {
        // History-restored Inertia props are not authority to send a purchase
        // to a destination that may have been replaced or disconnected.
        current = await fetchJson('/purchase-tracking/pending');
    } catch {
        return;
    }
    if (!current?.installed || !Array.isArray(current.pending)) return;
    for (const receipt of current.pending) {
        if (!receipt || typeof receipt !== 'object') continue;
        if (!receipts.some(requested => requested.receipt_id === receipt.receipt_id && requested.binding_id === receipt.binding_id)) continue;
        if (!Number.isSafeInteger(receipt.value_cents) || receipt.value_cents <= 0
            || !/^[A-Z]{3}$/.test(receipt.currency || '') || !/^AW-\d+\/[A-Za-z0-9_-]+$/.test(receipt.send_to || '')
            || typeof receipt.transaction_id !== 'string' || !/^[A-Za-z0-9:_.-]{1,128}$/.test(receipt.transaction_id)
            || typeof receipt.binding_id !== 'string' || !/^[a-f0-9]{64}$/.test(receipt.binding_id)
            || !Number.isSafeInteger(receipt.receipt_id)
            || (receipt.ack_url !== `/purchase-tracking/${receipt.receipt_id}/invoked`
                && receipt.ack_url !== `${window.location.origin}/purchase-tracking/${receipt.receipt_id}/invoked`)) continue;
        if (typeof window.gtag !== 'function') continue;
        const key = `${receipt.send_to}:${receipt.transaction_id}`;
        if (!invoked.has(key)) {
            try {
                const awId = receipt.send_to.split('/')[0];
                if (!configured.has(awId)) {
                    window.gtag('config', awId);
                    configured.add(awId);
                }
                window.gtag('event', 'conversion', {
                    send_to: receipt.send_to, transaction_id: receipt.transaction_id,
                    value: receipt.value_cents / 100, currency: receipt.currency,
                });
                invoked.add(key);
            } catch {
                continue;
            }
        }
        try {
            await fetchJson(receipt.ack_url, { method: 'POST', json: { binding_id: receipt.binding_id } });
        } catch {
            // The persisted receipt stays pending; never claim Google success.
        }
    }
}
