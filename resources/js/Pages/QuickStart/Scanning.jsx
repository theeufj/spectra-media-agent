import React from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useJobWatch } from '@/hooks/useJobWatch';
import ForecastPanel from '@/Components/ForecastPanel';
import { SetupStages } from '@/Components/SetupJourney';

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
            <p className={`font-medium ${state === 'pending' ? 'text-gray-500' : 'text-gray-900'}`}>{label}</p>
            {detail && state !== 'pending' && <p className="text-sm text-gray-500">{detail}</p>}
        </div>
    </div>
);

// Exported for tests: pure rendering of the wait, given the watch state.
export function ScanningProgress({ phase, data, website, recovery }) {
    const pages = data?.pages ?? 0;

    if (phase === 'failed') {
        return (
            <div className="text-center">
                <p className="text-4xl mb-4">⚠️</p>
                <h1 className="text-2xl font-bold text-gray-900 mb-2">We couldn't finish scanning your website</h1>
                <p className="text-gray-600 mb-6">
                    {data?.failure_reason || 'Something blocked the scan.'} You can add your content manually and we'll build from that instead.
                </p>
                {recovery || <a href="/knowledge-base" className="text-brand-dark underline">Add content manually</a>}
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
                    We have not received a finished business profile. You can give us the details below to continue.
                </p>
                {recovery}
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

            {/*
                The wait was dead time, and the answer to "is this worth
                anything?" is already available from the URL alone. Market size
                only: there is no campaign and no order value yet, so there is
                no honest revenue figure to show here.
            */}
            <div className="mt-8 max-w-md mx-auto">
                <ForecastPanel variant="market" />
            </div>
        </div>
    );
}

function BusinessBrief({ customerName }) {
    const { data, setData, post, processing, errors } = useForm({ business_name: customerName || '', business_description: '' });
    return <form className="mt-6 space-y-4 text-left" onSubmit={event => { event.preventDefault(); post(route('quick-start.business-brief'), { preserveState: false }); }}>
        <h2 className="text-lg font-semibold text-gray-900">Tell us about your business</h2>
        <label className="block text-sm font-medium text-gray-700">Business name
            <input required value={data.business_name} onChange={event => setData('business_name', event.target.value)} className="mt-1 w-full rounded-lg border-gray-300" />
        </label>
        {errors.business_name && <p role="alert" className="text-sm text-red-700">{errors.business_name}</p>}
        <label className="block text-sm font-medium text-gray-700">What do you sell, who is it for, and why should they choose you?
            <textarea required minLength={300} maxLength={12000} rows={7} value={data.business_description} onChange={event => setData('business_description', event.target.value)} className="mt-2 w-full rounded-lg border-gray-300" aria-describedby="brief-help" />
        </label>
        <p id="brief-help" className="text-sm text-gray-500">Include your offer, service area and any confirmed selling points. At least 300 characters. We will ask you to check the resulting profile before payment.</p>
        {errors.business_description && <p role="alert" className="text-sm text-red-700">{errors.business_description}</p>}
        <button disabled={processing} className="rounded-lg bg-brand-dark px-5 py-3 font-medium text-white disabled:opacity-50">{processing ? 'Saving…' : 'Build my business profile'}</button>
    </form>;
}

export default function Scanning({ customerName, website, setupOnly = false, manualEntry = false, baselineUpdatedAt = null }) {
    const { phase, data } = useJobWatch(route('brand-guidelines.status'), {
        enabled: !manualEntry,
        interval: 4000,
        isDone: (d) => d?.exists === true && (!baselineUpdatedAt || d.updated_at !== baselineUpdatedAt),
        isFailed: (d) => d?.failed === true,
        onDone: () => router.visit(route('brand-guidelines.index', { review: 1 })),
    });

    return (
        <AuthenticatedLayout>
            <Head title="Scanning your website" />
            <div className="min-h-[70vh] flex items-center justify-center py-12 px-4">
                <div className="w-full max-w-2xl bg-white shadow-sm rounded-lg p-10">
                    {setupOnly && <SetupStages stage={0} />}
                    <ScanningProgress phase={manualEntry ? 'failed' : phase} data={manualEntry ? { failure_reason: 'We need a little more information about your offer.' } : data} website={website} recovery={<BusinessBrief customerName={customerName} />} />
                    {phase === 'watching' && <details className="mt-8"><summary className="cursor-pointer text-sm text-brand-dark">Prefer to describe your business yourself?</summary><BusinessBrief customerName={customerName} /></details>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
