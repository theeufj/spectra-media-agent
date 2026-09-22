import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchJson } from '@/utils/http';

const response = (status = 200) => ({ ok: status < 400, status, text: async () => '{}' });

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="original-page-token">';
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/';
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response()));
});
afterEach(() => {
    document.head.innerHTML = '';
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/';
    vi.unstubAllGlobals();
});

describe('JSON session tokens', () => {
    it('uses the current decoded cookie instead of a stale page token on every write', async () => {
        document.cookie = 'XSRF-TOKEN=encrypted%2Bfirst%3D; Path=/';
        await fetchJson('/keywords/inline-research', { method: 'POST', json: { max_keywords: 20 } });
        const first = fetch.mock.calls[0][1];
        expect(first.headers['X-XSRF-TOKEN']).toBe('encrypted+first=');
        expect(first.headers).not.toHaveProperty('X-CSRF-TOKEN');
        expect(first.credentials).toBe('same-origin');

        document.cookie = 'XSRF-TOKEN=rotated-token; Path=/';
        await fetchJson('/draft', { method: 'PATCH', json: {} });
        expect(fetch.mock.calls[1][1].headers['X-XSRF-TOKEN']).toBe('rotated-token');
    });

    it('retains the meta fallback when no cookie exists and omits tokens from reads', async () => {
        await fetchJson('/draft', { method: 'POST', json: {} });
        expect(fetch.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('original-page-token');
        await fetchJson('/status');
        expect(fetch.mock.calls[1][1].headers).not.toHaveProperty('X-CSRF-TOKEN');
    });

    it('does not automatically replay other mutations after a rejection', async () => {
        fetch.mockResolvedValue(response(419));
        await expect(fetchJson('/billing/ad-spend/add-credit', { method: 'POST', json: {} }))
            .rejects.toMatchObject({ status: 419 });
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
