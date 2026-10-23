import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
import { fetchJson } from '@/utils/http';

const receipt = {
    receipt_id: 12, transaction_id: 'paid_setup:cs_real_receipt', value_cents: 99900, currency: 'USD',
    send_to: 'AW-18532358762/actual-label', binding_id: 'a'.repeat(64), ack_url: '/purchase-tracking/12/invoked',
};
let invokeVerifiedPurchases;

beforeEach(async () => {
    vi.resetModules();
    fetchJson.mockReset();
    window.gtag = vi.fn();
    ({ invokeVerifiedPurchases } = await import('@/utils/verifiedPurchases'));
});

describe('Verified paid checkout conversion bridge', () => {
    it('uses the current server-authorized receipt, exact target, actual currency/value and stable transaction ID', async () => {
        fetchJson.mockResolvedValue({ installed: true, pending: [receipt] });
        await invokeVerifiedPurchases([receipt]);
        await invokeVerifiedPurchases([receipt]);

        expect(fetchJson).toHaveBeenNthCalledWith(1, '/purchase-tracking/pending');
        expect(window.gtag).toHaveBeenCalledWith('config', 'AW-18532358762');
        expect(window.gtag).toHaveBeenCalledWith('event', 'conversion', {
            send_to: receipt.send_to, transaction_id: receipt.transaction_id, value: 999, currency: 'USD',
        });
        expect(window.gtag).toHaveBeenCalledTimes(2);
        expect(fetchJson).toHaveBeenCalledWith(receipt.ack_url, { method: 'POST', json: { binding_id: receipt.binding_id } });
    });

    it('does not consume a purchase while gtag is unavailable', async () => {
        delete window.gtag;
        await invokeVerifiedPurchases([receipt]);
        expect(fetchJson).not.toHaveBeenCalled();
    });

    it('never emits a stale history-restored receipt after the destination is disconnected or replaced', async () => {
        for (const current of [{ installed: false, pending: [] }, { installed: true, pending: [] },
            { installed: true, pending: [{ ...receipt, binding_id: 'b'.repeat(64), send_to: 'AW-99/new-goal' }] }]) {
            fetchJson.mockResolvedValueOnce(current);
            await invokeVerifiedPurchases([receipt]);
        }
        expect(window.gtag).not.toHaveBeenCalled();
        expect(fetchJson).toHaveBeenCalledTimes(3);
    });

    it('fails closed when current authority cannot be checked and rejects zero or malformed purchases', async () => {
        fetchJson.mockRejectedValueOnce(new Error('Unavailable'));
        await invokeVerifiedPurchases([receipt]);
        for (const override of [{ value_cents: 0 }, { value_cents: 1.25 }, { currency: '' }, { transaction_id: '' },
            { ack_url: 'https://another.example/collect' }]) {
            fetchJson.mockResolvedValueOnce({ installed: true, pending: [{ ...receipt, ...override }] });
            await invokeVerifiedPurchases([receipt]);
        }
        expect(window.gtag).not.toHaveBeenCalled();
        expect(fetchJson).toHaveBeenCalledTimes(6);
    });
});
