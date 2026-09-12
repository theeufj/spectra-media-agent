import React from 'react';
import { Head, usePage } from '@inertiajs/react';

/*
 * Re-states the server's title to Inertia, and nothing else.
 *
 * app.blade.php renders `<title inertia>` from the controller's meta. The
 * `inertia` attribute hands the element to Inertia, which then *owns* it: a page
 * that renders no <Head> title makes Inertia reset it, and app.jsx's callback
 * turns the empty string into a bare " - Site to Spend". So stripping the
 * duplicated <Head> blocks out of the page components — which is what stopped
 * them shipping two descriptions and two og:titles apiece — left every public
 * page with a correct title in the HTML and a broken one in the tab.
 *
 * Reading the shared `meta` prop rather than taking one keeps the controller as
 * the single source. This is propagation, not duplication: there is still
 * exactly one place the string is written.
 *
 * Client-side navigation between marketing pages needs this too — without it the
 * tab keeps the previous page's title after an Inertia <Link>.
 */
export default function PageTitle() {
    const { meta } = usePage().props;

    if (! meta?.title) {
        return null;
    }

    return <Head title={meta.title} />;
}
