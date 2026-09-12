import { describe, it, expect } from 'vitest';
import { money, count, percent } from '@/utils/format';

/**
 * The shared formatting helpers.
 *
 * These had no callers and no tests — 216 hand-rolled toFixed/toLocaleString
 * calls sat across the pages instead — so the behaviour their own
 * documentation advertised had never been executed. The first thing adopting
 * them found a RangeError in the documented usage.
 */
// The currency symbol follows the runner's locale, which is not the point of
// any of these — every assertion here is about the digits.
describe('money', () => {
    it('formats with cents by default', () => {
        expect(money(1234.5, 'USD')).toContain('1,234.50');
    });

    it('drops the cents when asked, which is what the docs recommend', () => {
        // minimumFractionDigits is hardcoded to 2, so overriding only the
        // maximum used to throw "maximumFractionDigits value is out of range"
        // — the exact call the docblock suggests for axis labels.
        expect(() => money(1500, 'USD', { maximumFractionDigits: 0 })).not.toThrow();

        const formatted = money(1500, 'USD', { maximumFractionDigits: 0 });
        expect(formatted).toContain('1,500');
        expect(formatted).not.toContain('1,500.00');
    });

    it('still honours an explicit minimum alongside a lowered maximum', () => {
        expect(money(1500, 'USD', { minimumFractionDigits: 1, maximumFractionDigits: 1 })).toContain('1,500.0');
    });

    it('disambiguates currencies rather than printing a bare dollar sign', () => {
        // A customer's forecast in AUD must not read as USD.
        expect(money(400, 'AUD')).toContain('400.00');
        expect(money(400, 'AUD')).not.toBe(money(400, 'USD'));
    });

    it('treats a missing amount as zero rather than NaN', () => {
        expect(money(null, 'USD')).toContain('0.00');
    });
});

describe('count', () => {
    it('groups thousands and drops fractions', () => {
        expect(count(1234567)).toBe('1,234,567');
        expect(count(13.5)).toBe('14');
    });
});

describe('percent', () => {
    it('takes a percentage, not a ratio', () => {
        expect(percent(2.4)).toBe('2.4%');
        expect(percent(3, { digits: 0 })).toBe('3%');
    });
});
