/*
 * One rule, earning its keep.
 *
 * Three crashes in a single day came from an identifier that did not exist in
 * the scope that used it, and every one of them shipped through a green test
 * suite because nothing statically checked the JSX:
 *
 *   - <Link href={null}> in SetupProgressNav: Inertia calls href.toString()
 *     inside a useMemo, so a step with no action took the whole dashboard to
 *     the error boundary.
 *   - `campaigns` read from a module-scope component in Campaigns/Show: the
 *     page crashed at the exact moment a customer signed off their campaign.
 *   - `setupOnly` used in Campaigns/CreateWizard without being destructured
 *     from props: step 2 of the wizard threw for every customer.
 *
 * The last two are literally what no-undef is for, and the rule is quiet: the
 * whole of resources/js passes it. The point of keeping the config this narrow
 * is that it stays quiet — a lint run nobody trusts is a lint run nobody reads.
 * Style is Prettier's business and is not enforced here.
 */
const browser = {
    window: 'readonly', document: 'readonly', console: 'readonly',
    fetch: 'readonly', setTimeout: 'readonly', clearTimeout: 'readonly',
    setInterval: 'readonly', clearInterval: 'readonly', localStorage: 'readonly',
    sessionStorage: 'readonly', navigator: 'readonly', location: 'readonly',
    URL: 'readonly', URLSearchParams: 'readonly', FormData: 'readonly',
    Image: 'readonly', Blob: 'readonly', File: 'readonly', FileReader: 'readonly',
    alert: 'readonly', confirm: 'readonly', requestAnimationFrame: 'readonly',
    IntersectionObserver: 'readonly', ResizeObserver: 'readonly',
    AbortController: 'readonly', CustomEvent: 'readonly', Event: 'readonly',
    HTMLElement: 'readonly', structuredClone: 'readonly',
    // Real at runtime, absent from source: the browser notifications API and
    // the Google tag the conversion helpers call.
    Notification: 'readonly', gtag: 'readonly',
    queueMicrotask: 'readonly', crypto: 'readonly', process: 'readonly',
    // Ziggy publishes these; they are real at runtime and absent from source.
    route: 'readonly', Ziggy: 'readonly', axios: 'readonly',
};

import reactHooks from 'eslint-plugin-react-hooks';

export default [
    {
        files: ['resources/js/**/*.jsx', 'resources/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
            globals: browser,
        },
        /*
           react-hooks is registered but silent. The codebase already carries
           eslint-disable comments for exhaustive-deps, and ESLint errors on a
           disable comment naming a rule it has never heard of — so the plugin
           has to be here for those lines to resolve. Turning the rules on is a
           separate piece of work with a real backlog behind it; this commit is
           about no-undef.
        */
        plugins: { 'react-hooks': reactHooks },
        rules: { 'no-undef': 'error' },
    },
    {
        // Vitest runs on Node, where `global` exists.
        files: ['resources/js/tests/**/*.jsx', 'resources/js/tests/**/*.js'],
        languageOptions: { globals: { ...browser, global: 'writable' } },
    },
];
