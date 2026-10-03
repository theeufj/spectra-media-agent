import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const pixel = readFileSync(resolve(process.cwd(), 'public/js/spectra-pixel.js'), 'utf8');

describe('website attribution pixel', () => {
    it('sends to SiteToSpend across domains without putting a secret in the page', () => {
        const script = document.createElement('script');
        script.src = 'https://sitetospend.com/js/spectra-pixel.js';
        script.setAttribute('data-site-id', '123e4567-e89b-42d3-a456-426614174000');
        Object.defineProperty(document, 'currentScript', { configurable: true, value: script });
        const beacon = vi.fn(() => true);
        Object.defineProperty(navigator, 'sendBeacon', { configurable: true, value: beacon });

        try {
            window.eval(pixel);
            expect(beacon).toHaveBeenCalledOnce();
            expect(beacon.mock.calls[0][0]).toBe('https://sitetospend.com/api/tracking/touchpoint');
            expect(beacon.mock.calls[0][1].get('site_id')).toBe('123e4567-e89b-42d3-a456-426614174000');
            expect(beacon.mock.calls[0][1].has('secret')).toBe(false);

            window.SpectraPixel.trackConversion('lead', 0);
            expect(beacon.mock.calls[1][0]).toBe('https://sitetospend.com/api/tracking/conversion');
            expect(beacon.mock.calls[1][1].get('conversion_type')).toBe('lead');
            expect(beacon.mock.calls[1][1].get('event_id')).toMatch(/^[a-f0-9-]{36}$/i);
        } finally {
            delete document.currentScript;
            delete navigator.sendBeacon;
        }
    });
});
