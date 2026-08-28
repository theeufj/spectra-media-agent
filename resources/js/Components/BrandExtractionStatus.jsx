import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { useJobWatch } from '@/hooks/useJobWatch';
import JobProgressBanner from '@/Components/JobProgressBanner';

/**
 * Narrates a brand-guideline extraction from dispatch to landing. The POST
 * that starts the job returns in milliseconds; the job itself reads the
 * whole knowledge base and calls Gemini, which takes minutes. This watches
 * /brand-guidelines/status until the guideline's updated_at moves past the
 * baseline (success), the server records a scan failure (failed), or ten
 * minutes pass (timeout — the completion email still covers the long tail).
 *
 * @param {boolean} watching             a run was dispatched (or may be running from onboarding)
 * @param {?string} baselineUpdatedAt    the guideline's updated_at when the watch began
 * @param {() => void} [onSettled]       fired once when the watch lands anywhere terminal
 */
export default function BrandExtractionStatus({ watching, baselineUpdatedAt, onSettled }) {
    const [dismissed, setDismissed] = useState(false);

    useEffect(() => {
        if (watching) setDismissed(false);
    }, [watching]);

    const { phase, data } = useJobWatch(route('brand-guidelines.status'), {
        enabled: watching,
        interval: 5000,
        isFailed: (d) => d?.failed === true,
        isDone: (d) => {
            if (!d?.exists) return false;
            if (!baselineUpdatedAt) return true;

            return new Date(d.updated_at).getTime() > new Date(baselineUpdatedAt).getTime();
        },
        onDone: () => {
            // Pull the fresh guideline into the page without losing scroll.
            router.reload({ only: ['brandGuideline'], preserveScroll: true });
            onSettled?.();
        },
        onFailed: () => onSettled?.(),
    });

    if (dismissed) return null;

    const banners = {
        watching: {
            state: 'running',
            title: 'Analysing your website…',
            message: 'We\'re reading your pages and building your brand profile. This usually takes a few minutes — you can leave this page; we\'ll also email you when it\'s ready.',
        },
        done: {
            state: 'done',
            title: 'Your brand profile is ready',
            message: 'We\'ve updated this page with what we found. Review it and correct anything we got wrong.',
        },
        failed: {
            state: 'failed',
            title: 'We couldn\'t finish analysing your website',
            message: data?.failure_reason || 'Something went wrong during the scan. Add your content manually and we\'ll build from that instead.',
        },
        timeout: {
            state: 'timeout',
            title: 'This is taking longer than usual',
            message: 'The analysis is still running in the background. We\'ll email you the moment it\'s ready — no need to keep this page open.',
        },
        disconnected: {
            state: 'timeout',
            title: 'We lost the connection',
            message: 'Refresh the page to keep watching — the analysis continues in the background either way.',
        },
    };

    const banner = banners[phase];
    if (!banner) return null;

    return (
        <JobProgressBanner
            state={banner.state}
            title={banner.title}
            message={banner.message}
            onDismiss={() => setDismissed(true)}
        />
    );
}
