import React from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useJobWatch } from '@/hooks/useJobWatch';

/**
 * The post-QuickStart holding screen. The old flow dumped the user on the
 * dashboard with a toast while the scan ran invisibly; this one holds them
 * in a narrated wait — scanning → building the brand profile — and lands on
 * the brand guidelines review the moment they exist.
 */

const Spinner = ({ className = 'h-5 w-5' }) => (
    <svg data-testid="scan-spinner" className={`animate-spin ${className}`} fill="none" viewBox="0 0 24 24" aria-label="working">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
    </svg>
);

const Step = ({ state, label, detail }) => (
    <div className="flex items-start gap-3">
        <span className="mt-0.5 flex-shrink-0 w-6 h-6 flex items-center justify-center">
            {state === 'done' && <span className="text-green-600 text-lg" aria-label="done">✓</span>}
            {state === 'active' && <Spinner className="h-5 w-5 text-brand-primary" />}
            {state === 'pending' && <span className="w-2.5 h-2.5 rounded-full bg-gray-300 inline-block" aria-label="pending" />}
        </span>
        <div>
            <p className={`font-medium ${state === 'pending' ? 'text-gray-400' : 'text-gray-900'}`}>{label}</p>
            {detail && state !== 'pending' && <p className="text-sm text-gray-500">{detail}</p>}
        </div>
    </div>
);

// Exported for tests: pure rendering of the wait, given the watch state.
export function ScanningProgress({ phase, data, website }) {
    const pages = data?.pages ?? 0;

    if (phase === 'failed') {
        return (
            <div className="text-center">
                <p className="text-4xl mb-4">⚠️</p>
                <h1 className="text-2xl font-bold text-gray-900 mb-2">We couldn't finish scanning your website</h1>
                <p className="text-gray-600 mb-6">
                    {data?.failure_reason || 'Something blocked the scan.'} You can add your content manually and we'll build from that instead.
                </p>
                <div className="flex justify-center gap-3">
                    <a href="/knowledge-base" className="px-5 py-2.5 bg-brand-primary text-white rounded-md font-semibold">Add content manually</a>
                    <a href="/dashboard" className="px-5 py-2.5 bg-white border border-gray-300 rounded-md font-semibold text-gray-700">Go to dashboard</a>
                </div>
            </div>
        );
    }

    if (phase === 'disconnected') {
        return (
            <div className="text-center">
                <p className="text-4xl mb-4">🔌</p>
                <h1 className="text-2xl font-bold text-gray-900 mb-2">We lost the connection</h1>
                <p className="text-gray-600 mb-6">
                    The page can't reach the server — your session may have ended. Refresh to pick up where you left off; the work continues in the background either way.
                </p>
                <button onClick={() => window.location.reload()} className="px-5 py-2.5 bg-brand-primary text-white rounded-md font-semibold">
                    Refresh
                </button>
            </div>
        );
    }

    if (phase === 'timeout') {
        return (
            <div className="text-center">
                <p className="text-4xl mb-4">⏱</p>
                <h1 className="text-2xl font-bold text-gray-900 mb-2">This is taking longer than usual</h1>
                <p className="text-gray-600 mb-6">
                    The scan is still running in the background — we'll email you the moment your brand profile is ready. No need to wait here.
                </p>
                <a href="/dashboard" className="px-5 py-2.5 bg-brand-primary text-white rounded-md font-semibold">Go to dashboard</a>
            </div>
        );
    }

    const scanning = pages === 0;

    return (
        <div>
            <div className="text-center mb-8">
                <h1 className="text-2xl font-bold text-gray-900 mb-2">Setting up {website || 'your website'}</h1>
                <p className="text-gray-600">This usually takes a few minutes. Sit tight — or leave, and we'll email you when it's ready.</p>
            </div>
            <div className="space-y-5 max-w-md mx-auto">
                <Step
                    state={scanning ? 'active' : 'done'}
                    label="Scanning your website"
                    detail={scanning ? 'Finding your pages…' : `${pages} page${pages === 1 ? '' : 's'} read`}
                />
                <Step
                    state={scanning ? 'pending' : 'active'}
                    label="Building your brand guidelines"
                    detail="Your voice, services, colours and audience — everything your ads start from."
                />
                <Step state="pending" label="Reviewing your brand profile together" />
            </div>
        </div>
    );
}

export default function Scanning({ customerName, website }) {
    const { phase, data } = useJobWatch(route('brand-guidelines.status'), {
        enabled: true,
        interval: 4000,
        isDone: (d) => d?.exists === true,
        isFailed: (d) => d?.failed === true,
        onDone: () => router.visit(route('brand-guidelines.index', { review: 1 })),
    });

    return (
        <AuthenticatedLayout>
            <Head title="Scanning your website" />
            <div className="min-h-[70vh] flex items-center justify-center py-12 px-4">
                <div className="w-full max-w-2xl bg-white shadow-sm rounded-lg p-10">
                    <ScanningProgress phase={phase} data={data} website={website} customerName={customerName} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
