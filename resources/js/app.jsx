import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import ErrorBoundary from './Components/ErrorBoundary';
import { ToastProvider } from './Components/Toast';
import { initConversions } from '@/utils/conversions';

// Reload the page when the session CSRF token has expired (419).
// This gets a fresh session cookie + token instead of showing an error.
router.on('invalid', (event) => {
    if (event.detail.response.status === 419) {
        event.preventDefault();
        window.location.reload();
    }
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Public marketing pages ship complete, length-tuned <title> tags that already
// carry the brand. Appending the app name a second time pushed the home page to
// 63 characters, past the ~60 Google renders before truncating. Only bare in-app
// titles ("Dashboard", "Quick Start") get the brand appended.
const brands = ['sitetospend', 'Site to Spend', 'Real Property Ads'];

// The suffix must be the TENANT's name, not the build-time app name — a
// visitor on realpropertyads.com used to see "Register - Site to Spend" in
// their tab. Captured from the shared tenant prop in setup(), which runs
// before the first title render.
let brandName = appName;

createInertiaApp({
    title: (title) => (brands.some((brand) => title.includes(brand)) ? title : `${title} - ${brandName}`),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        brandName = props.initialPage.props.tenant?.name ?? appName;
        initConversions(props.initialPage.props.conversionTargets);
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <ToastProvider>
                    <App {...props} />
                </ToastProvider>
            </ErrorBoundary>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
