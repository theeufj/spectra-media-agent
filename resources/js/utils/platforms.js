/**
 * The single source of truth for how an ad platform is named and coloured.
 *
 * Platform colour was previously defined four times in two incompatible
 * systems: two lowercase-keyed hex maps and two Capitalised-keyed Tailwind
 * class maps, which disagreed with each other — Facebook was `#1877F2` in one
 * map and `bg-indigo-500` in another, in the same file. Two consequences:
 *
 *  - LINKEDIN RENDERED GREY. The class maps keyed `'LinkedIn'` while the API
 *    emits `ucfirst('linkedin')` = `'Linkedin'`, so every LinkedIn segment fell
 *    through to `bg-gray-400` and the customer's LinkedIn spend read as
 *    "other". Everything here is keyed lowercase and looked up through
 *    `platformKey()`, which lowercases first, so casing can never do that again.
 *  - GOOGLE AND FACEBOOK WERE THE SAME COLOUR. The vendor blues `#4285F4` and
 *    `#1877F2` sat adjacent in the same stacked bar as its two largest
 *    segments. The hues below are the first four `CATEGORICAL.light` slots,
 *    which are validated for adjacent-pair separability under colour-vision
 *    deficiency; the vendor blues are not, and brand fidelity is not worth a
 *    chart nobody can read.
 *
 * TWO RULES FOR CALLERS:
 *
 *  1. COLOUR COMES OUT AS HEX, NOT AS A TAILWIND CLASS. Use it in `style`:
 *     `style={{ backgroundColor: platformHex(p) }}`. This file is `.js`, and
 *     `tailwind.config.js` only scans `resources/js/**\/*.jsx` — a class string
 *     written here is never generated, so it would silently render unstyled.
 *     One system, deliberately.
 *  2. NEVER PUT TEXT ON A PLATFORM COLOUR. None of the four carries white text
 *     at AA (best is 4.42:1 for Facebook, worst 2.17:1 for LinkedIn), and dark
 *     ink fails on Facebook. Label beside the swatch or segment, never inside
 *     it — which is also what `palette.js` requires of these slots generally.
 */

import { CATEGORICAL, INK } from '@/Components/Charts/palette';

/**
 * Canonical order. Assign a chart's colours from this order and never from the
 * order the API happened to return, so a platform keeps its colour when a
 * filter drops one of its neighbours.
 */
export const PLATFORM_ORDER = ['google', 'facebook', 'microsoft', 'linkedin'];

export const PLATFORMS = {
    google:    { key: 'google',    label: 'Google Ads',    short: 'Google',    hex: CATEGORICAL.light[0] },
    facebook:  { key: 'facebook',  label: 'Facebook Ads',  short: 'Facebook',  hex: CATEGORICAL.light[1] },
    microsoft: { key: 'microsoft', label: 'Microsoft Ads', short: 'Microsoft', hex: CATEGORICAL.light[2] },
    linkedin:  { key: 'linkedin',  label: 'LinkedIn Ads',  short: 'LinkedIn',  hex: CATEGORICAL.light[3] },
};

/**
 * Anything unrecognised gets the palette's muted chrome grey rather than a
 * series colour — an unknown platform must not be mistakable for a known one.
 */
export const UNKNOWN_PLATFORM = { key: null, label: 'Other', short: 'Other', hex: INK.light.muted };

/**
 * The same platform arrives spelled several ways depending on which layer sent
 * it: `'Linkedin'` from `ucfirst()`, `'google_ads'` from a couple of the job
 * payloads, `'meta'` and `'bing'` from the vendor SDKs. Normalise once here so
 * no lookup site has to know that.
 */
const ALIASES = {
    meta: 'facebook',
    instagram: 'facebook',
    bing: 'microsoft',
    msft: 'microsoft',
};

/**
 * @param {string|null|undefined} value
 * @returns {string|null} canonical lowercase key, or null when unrecognised.
 */
export function platformKey(value) {
    if (typeof value !== 'string') return null;

    const normalised = value.trim().toLowerCase().replace(/[\s-]+/g, '_').replace(/_?ads?$/, '');
    const key = Object.prototype.hasOwnProperty.call(ALIASES, normalised) ? ALIASES[normalised] : normalised;

    return Object.prototype.hasOwnProperty.call(PLATFORMS, key) ? key : null;
}

/**
 * Full descriptor, never null — safe to destructure at a call site.
 *
 * @param {string|null|undefined} value
 */
export function platform(value) {
    const key = platformKey(value);

    return key ? PLATFORMS[key] : UNKNOWN_PLATFORM;
}

/** Display name, e.g. "LinkedIn Ads". Falls back to "Other". */
export function platformLabel(value) {
    return platform(value).label;
}

/** Chart/swatch hex. Use in `style`, not in a class name — see the note above. */
export function platformHex(value) {
    return platform(value).hex;
}
