/**
 * Number, money and date formatting for every page that renders a figure.
 *
 * Lifted from the working formatter at `Billing/AdSpend.jsx` and generalised.
 * The three failures this exists to stop:
 *
 *  - RAGGED DECIMALS. `Number(x).toLocaleString()` drops trailing zeros, so a
 *    server value rounded to 2dp printed `$1,234`, `$87.4` and `$87.40` in the
 *    same column and the decimal points did not line up. `money()` always
 *    prints exactly two.
 *  - THE USD HARDCODE. `customers.currency_code` and `ad_spend_credits.currency`
 *    are real columns and balances are genuinely denominated in them, but every
 *    formatter pinned `currency: 'USD'`. An Australian holding A$400 of credit
 *    read "$400.00" and could not tell what would hit their card. Currency is
 *    an argument here; pass it whenever the controller knows it.
 *  - FIVE DATE CONVENTIONS. Pinned en-GB, pinned en-US, pinned en-AU, bare
 *    locale-following, and raw ISO — two of them inside one card. Everything
 *    here follows the viewer's locale, which is what `Inbox/Index.jsx` already
 *    did and the rest of the product did not.
 *
 * All five are safe on null/undefined/garbage: a formatter that throws inside
 * render takes the whole page down with it, and these run on props that come
 * straight off nullable columns.
 */

/** What a figure reads as when there is genuinely nothing to show. */
const EMPTY = '—';

/**
 * Money and counts treat a missing value as zero, matching the AdSpend
 * behaviour this replaces — "no transactions yet" means a $0.00 balance, not
 * an unknown one. Dates do not: an absent date is unknown, never the epoch.
 */
function toNumber(value) {
    const n = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(n) ? n : 0;
}

/**
 * Intl throws on a bad currency — TypeError when it is undefined, RangeError
 * when it is not a well-formed code. Controllers are being threaded through
 * one at a time and `currency_code` is nullable, so an un-migrated page would
 * otherwise blank out entirely rather than merely showing the wrong symbol.
 */
function currencyCode(currency) {
    if (typeof currency !== 'string') return 'USD';

    const code = currency.trim().toUpperCase();

    return /^[A-Z]{3}$/.test(code) ? code : 'USD';
}

/**
 * Format a monetary amount.
 *
 * Locale follows the viewer; the currency does not. Intl then disambiguates
 * exactly where it matters — a US-locale viewer sees "A$400.00" for AUD and
 * "$400.00" for USD, an AU-locale viewer sees the reverse — so the symbol on
 * screen always answers "which dollars is this?".
 *
 * @param {number|string|null} value
 * @param {string} [currency] ISO 4217 code, e.g. the customer's `currency_code`.
 * @param {Intl.NumberFormatOptions} [options] Overrides, e.g. `{ maximumFractionDigits: 0 }`
 *   for chart axis labels where cents are noise.
 */
export function money(value, currency = 'USD', options = {}) {
    /*
     * The floor follows the ceiling when a caller lowers it.
     *
     * Intl throws RangeError when minimumFractionDigits exceeds
     * maximumFractionDigits, so the `{ maximumFractionDigits: 0 }` this
     * function's own documentation recommends for axis labels used to crash
     * against the hardcoded minimum of 2. Nothing had called it that way yet —
     * this helper had no adopters at all — so the advertised usage had never
     * actually been run.
     */
    const max = options.maximumFractionDigits;

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currencyCode(currency),
        minimumFractionDigits: max === undefined ? 2 : Math.min(2, max),
        maximumFractionDigits: 2,
        ...options,
    }).format(toNumber(value));
}

/**
 * Format a whole-number quantity — impressions, clicks, conversions, leads.
 *
 * @param {number|string|null} value
 * @param {Intl.NumberFormatOptions} [options]
 */
export function count(value, options = {}) {
    return new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 0,
        ...options,
    }).format(toNumber(value));
}

/**
 * Format a percentage.
 *
 * `value` is ALREADY a percentage, not a 0–1 ratio — that is the convention the
 * API uses (`stats.ctr` is 2.4 for 2.4%, `spend_share` is 31 for 31%). If you
 * are holding a ratio, scale it at the call site: `percent(similarity * 100)`.
 *
 * @param {number|string|null} value
 * @param {{digits?: number}} [options] Decimal places — 2 for CTR, 0 for a share.
 */
export function percent(value, { digits = 1 } = {}) {
    const formatted = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(toNumber(value));

    return `${formatted}%`;
}

/**
 * A bare `YYYY-MM-DD` is parsed by `new Date()` as UTC midnight and then
 * rendered in the viewer's zone, so a date-only column (report ranges, budget
 * effective dates) renders one day early for everyone west of Greenwich.
 * Splitting it into local Y/M/D keeps the day the server meant.
 */
function toDate(value) {
    if (value == null || value === '') return null;

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    if (typeof value === 'string') {
        const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
        if (dateOnly) {
            return new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]));
        }
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/**
 * Short date in the viewer's locale — "Aug 31, 2026" / "31 Aug 2026".
 *
 * @param {string|number|Date|null} value
 * @param {Intl.DateTimeFormatOptions} [options]
 */
export function date(value, options = {}) {
    const d = toDate(value);
    if (!d) return EMPTY;

    return d.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        ...options,
    });
}

/**
 * Date plus time in the viewer's locale — "Aug 31, 2026, 7:00 AM".
 *
 * @param {string|number|Date|null} value
 * @param {Intl.DateTimeFormatOptions} [options]
 */
export function dateTime(value, options = {}) {
    const d = toDate(value);
    if (!d) return EMPTY;

    return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        ...options,
    });
}
