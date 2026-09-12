import '@testing-library/jest-dom/vitest';

// route() is injected globally by Ziggy's @routes blade directive in the real
// app; tests get a stub that just echoes a recognisable path.
globalThis.route = (name, params) => {
    const suffix = params !== undefined && params !== null ? `/${params}` : '';

    return `/__route__/${name}${suffix}`;
};

/*
 * One locale for the whole suite.
 *
 * Everything user-visible in this app goes through Intl with an `undefined`
 * locale, deliberately — money and dates follow the *viewer*, so a US reader
 * sees "A$50.00" for AUD and an Australian one sees "$50.00", and the symbol
 * on screen always answers "which dollars is this?".
 *
 * That makes any assertion on a formatted string a test of whoever runs it.
 * Five of them passed on a laptop set to en-AU and failed on CI, which is
 * en-US: "1 Sept 2026" against "Sep 1, 2026", "$50.00" against "A$50.00". The
 * code was right both times.
 *
 * So the suite fixes a locale rather than inheriting one. en-AU because that is
 * where most customers are, and because a test that says "1 Sept 2026" should
 * be read by someone who writes dates that way. Tests that care about the
 * behaviour *across* locales must pass a locale explicitly — this only changes
 * what `undefined` means.
 */
const TEST_LOCALE = 'en-AU';

for (const name of ['NumberFormat', 'DateTimeFormat']) {
    const Original = Intl[name];
    const Patched = function (locales, options) {
        return new Original(locales ?? TEST_LOCALE, options);
    };
    Patched.supportedLocalesOf = (...args) => Original.supportedLocalesOf(...args);
    Patched.prototype = Original.prototype;
    Intl[name] = Patched;
}

// The toLocale* helpers reach ICU directly rather than through the constructors
// above, so they need the same default.
const localeMethods = [
    [Date.prototype, 'toLocaleDateString'],
    [Date.prototype, 'toLocaleTimeString'],
    [Date.prototype, 'toLocaleString'],
    [Number.prototype, 'toLocaleString'],
];

for (const [proto, method] of localeMethods) {
    const original = proto[method];
    proto[method] = function (locales, options) {
        return original.call(this, locales ?? TEST_LOCALE, options);
    };
}
