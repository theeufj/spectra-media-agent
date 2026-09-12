import { describe, it, expect } from 'vitest';
import { platformKey, platformLabel, platformHex, PLATFORM_ORDER } from '@/utils/platforms';

/**
 * A platform's name and colour are its identity, and both were wrong.
 *
 * LINKEDIN RENDERED GREY. CrossPlatformAnalyticsService:180 emits
 * `ucfirst($name)`, so LinkedIn arrives as "Linkedin" with a lowercase i,
 * while Analytics/CrossPlatform kept a colour map keyed "LinkedIn". Every
 * LinkedIn bar missed the lookup and fell through to bg-gray-400 — a
 * customer's LinkedIn spend rendered as an unknown platform, on the page whose
 * whole purpose is comparing platforms.
 *
 * THE SAME PLATFORM CHANGED COLOUR BETWEEN SCREENS. Four separate maps defined
 * these four platforms: Google was #4285F4 on the ROI page and #2a78d6 on the
 * dashboard. And on ROI the two vendor blues — Google #4285F4 beside Facebook
 * #1877F2 — were the two largest adjacent segments of one stacked bar.
 */
describe('platformKey', () => {
    it('survives every casing the backend actually sends', () => {
        // ucfirst('linkedin') is the exact string that made LinkedIn grey.
        for (const spelling of ['Linkedin', 'LinkedIn', 'linkedin', 'LINKEDIN', ' linkedin ']) {
            expect(platformKey(spelling)).toBe('linkedin');
        }
    });

    it('strips the "Ads" suffix the API sometimes appends', () => {
        expect(platformKey('Google Ads')).toBe('google');
        expect(platformKey('google_ads')).toBe('google');
    });

    it('maps the vendor aliases', () => {
        expect(platformKey('meta')).toBe('facebook');
        expect(platformKey('bing')).toBe('microsoft');
    });

    it('returns null for something genuinely unknown', () => {
        expect(platformKey('tiktok')).toBeNull();
        expect(platformKey(null)).toBeNull();
        expect(platformKey(42)).toBeNull();
    });
});

describe('platformLabel', () => {
    it('names a platform however it arrived', () => {
        expect(platformLabel('Linkedin')).toBe('LinkedIn Ads');
        expect(platformLabel('linkedin')).toBe('LinkedIn Ads');
    });

    it('calls an unknown platform Other rather than throwing', () => {
        expect(platformLabel('tiktok')).toBe('Other');
    });
});

describe('platformHex', () => {
    it('gives a known platform one colour, whatever the spelling', () => {
        expect(platformHex('Linkedin')).toBe(platformHex('linkedin'));
        expect(platformHex('Google Ads')).toBe(platformHex('google'));
    });

    it('never hands a known platform the unknown grey', () => {
        // This is the assertion that would have failed for LinkedIn.
        const other = platformHex('tiktok');

        for (const key of PLATFORM_ORDER) {
            expect(platformHex(key)).not.toBe(other);
        }
    });

    it('gives all four platforms distinct colours', () => {
        const hexes = PLATFORM_ORDER.map(platformHex);

        expect(new Set(hexes).size).toBe(PLATFORM_ORDER.length);
    });

    it('does not put Google and Facebook on the vendor blues', () => {
        // #4285F4 and #1877F2 sat adjacent in the same stacked bar and are
        // near-indistinguishable, worse still under colour-vision deficiency.
        expect(platformHex('google').toLowerCase()).not.toBe('#4285f4');
        expect(platformHex('facebook').toLowerCase()).not.toBe('#1877f2');
    });
});
