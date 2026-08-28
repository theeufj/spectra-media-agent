import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export default function QuickStart({ auth, demoUrl = null }) {
    // No country field: the server derives it (and the currency) from the
    // timezone below, rather than assuming US for everyone.
    const { data, setData, post, processing, errors, transform } = useForm({
        website_url: demoUrl || '',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        service_type: 'managed',
    });

    const [urlFocused, setUrlFocused] = useState(false);

    // A signup that came from the landing-page demo already told us their URL.
    // Submit it for them — from here, so the post carries the browser timezone
    // the server-side auto-process never had.
    const autoSubmitted = useRef(false);
    useEffect(() => {
        if (demoUrl && !autoSubmitted.current) {
            autoSubmitted.current = true;
            post(route('quick-start.process'));
        }
    }, []);

    // The placeholder shows a bare host, so accept one. This must happen in
    // transform(), which runs on the data actually being posted — a setData
    // immediately before post() hasn't committed yet, and the first submit
    // used to send the un-prefixed value and fail validation.
    transform((current) => {
        const url = current.website_url.trim();

        return {
            ...current,
            website_url: url && !url.match(/^https?:\/\//i) ? `https://${url}` : url,
        };
    });

    function handleSubmit(e) {
        e.preventDefault();
        post(route('quick-start.process'));
    }

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                    Quick Start
                </h2>
            }
        >
            <Head title="Quick Start" />

            <div className="py-12">
                <div className="max-w-2xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-8 text-center">
                            <div className="text-5xl mb-4">🚀</div>
                            <h1 className="text-2xl font-bold text-gray-900 mb-2">
                                Get Started in 30 Seconds
                            </h1>
                            <p className="text-gray-600 mb-8 max-w-md mx-auto">
                                Paste your website URL and our AI will scan your site, learn your brand, and prepare everything for your first campaign.
                            </p>

                            <form onSubmit={handleSubmit} className="max-w-lg mx-auto">
                                <div className="relative">
                                    <div className={`
                                        flex items-center border-2 rounded-xl transition-all duration-200
                                        ${urlFocused ? 'border-brand-primary shadow-lg shadow-brand-primary/20' : 'border-gray-200'}
                                        ${errors.website_url ? 'border-red-400' : ''}
                                    `}>
                                        <span className="pl-4 text-gray-400">
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                                            </svg>
                                        </span>
                                        <input
                                            type="text"
                                            value={data.website_url}
                                            onChange={e => setData('website_url', e.target.value)}
                                            onFocus={() => setUrlFocused(true)}
                                            onBlur={() => setUrlFocused(false)}
                                            placeholder="yourwebsite.com"
                                            className="flex-1 px-3 py-4 text-lg border-0 focus:ring-0 focus:outline-none rounded-xl"
                                            autoFocus
                                        />
                                        <button
                                            type="submit"
                                            disabled={processing || !data.website_url.trim()}
                                            className={`
                                                mr-2 px-6 py-2.5 rounded-lg font-medium text-white transition-all duration-200
                                                ${processing || !data.website_url.trim()
                                                    ? 'bg-gray-300 cursor-not-allowed'
                                                    : 'bg-brand-dark hover:bg-brand-darker shadow-md hover:shadow-lg'
                                                }
                                            `}
                                        >
                                            {processing ? (
                                                <span className="flex items-center">
                                                    <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                    </svg>
                                                    Scanning...
                                                </span>
                                            ) : 'Go'}
                                        </button>
                                    </div>
                                    {errors.website_url && (
                                        <p className="mt-2 text-sm text-red-600 text-left">{errors.website_url}</p>
                                    )}
                                </div>
                            </form>

                            {/* The fork: ongoing management or one-and-done.
                                Intent only — payment happens at the plan step. */}
                            <div className="mt-8 grid sm:grid-cols-2 gap-3 max-w-xl mx-auto text-left">
                                <button
                                    type="button"
                                    onClick={() => setData('service_type', 'managed')}
                                    className={`rounded-lg border-2 p-4 transition ${data.service_type === 'managed' ? 'border-brand-primary bg-brand-primary/10' : 'border-gray-200 bg-white hover:border-gray-300'}`}
                                >
                                    <p className="font-semibold text-gray-900 text-sm">Manage it for me</p>
                                    <p className="text-xs text-gray-500 mt-1">
                                        We build, launch and optimise your ads around the clock. Monthly plan.
                                    </p>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setData('service_type', 'setup_only')}
                                    className={`rounded-lg border-2 p-4 transition ${data.service_type === 'setup_only' ? 'border-brand-primary bg-brand-primary/10' : 'border-gray-200 bg-white hover:border-gray-300'}`}
                                >
                                    <p className="font-semibold text-gray-900 text-sm">Set it up once — US$999</p>
                                    <p className="text-xs text-gray-500 mt-1">
                                        We build your account, campaigns and tracking, then hand you the keys. One payment, nothing recurring.
                                    </p>
                                </button>
                            </div>

                            <div className="mt-10 grid grid-cols-3 gap-4 max-w-lg mx-auto text-center">
                                <div className="p-3">
                                    <div className="text-2xl mb-1">📚</div>
                                    <p className="text-xs text-gray-500">Scans your website content</p>
                                </div>
                                <div className="p-3">
                                    <div className="text-2xl mb-1">🎨</div>
                                    <p className="text-xs text-gray-500">Extracts brand guidelines</p>
                                </div>
                                <div className="p-3">
                                    <div className="text-2xl mb-1">🚀</div>
                                    <p className="text-xs text-gray-500">Prepares your first campaign</p>
                                </div>
                            </div>

                            <p className="mt-6 text-xs text-gray-400">
                                Or{' '}
                                <a href={route('customers.create')} className="text-brand-dark hover:text-brand-darker">
                                    set up manually
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
