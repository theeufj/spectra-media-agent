import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { brandTint } from '@/Components/Marketing/Hero';
import {
    ArrowRightIcon,
    DocumentTextIcon,
    GlobeAltIcon,
    RocketLaunchIcon,
    SwatchIcon,
} from '@heroicons/react/24/outline';

/*
 * The first screen a new client sees after verifying their email.
 *
 * Three things were wrong with it and all three were invisible in review:
 *
 *   - The selected service card was `bg-brand-primary/10`, and its focus ring
 *     `shadow-brand-primary/20`. An opacity modifier on a brand token compiles
 *     to no CSS (see Components/Marketing/Hero.jsx), so "selected" was a border
 *     colour and nothing else — and the choice between a monthly plan and a
 *     US$999 one-off was being signalled by a 2px edge.
 *   - Those two cards are a single either/or, but they were plain buttons. A
 *     screen reader was told there were two buttons, not which one was chosen.
 *     They are a radiogroup now, so the state is in the accessibility tree
 *     rather than only in the colour.
 *   - The URL field had a placeholder and no label, so the only description of
 *     the most important input in the product vanished the moment you typed.
 */

const STEPS = [
    { icon: DocumentTextIcon, label: 'Scans your website content' },
    { icon: SwatchIcon, label: 'Extracts your brand guidelines' },
    { icon: RocketLaunchIcon, label: 'Prepares your first campaign' },
];

const SERVICES = [
    {
        value: 'managed',
        title: 'Manage it for me',
        body: 'We build, launch and optimise your ads around the clock. Monthly plan.',
    },
    {
        value: 'setup_only',
        title: 'Set it up once — US$999',
        body: 'We build your account, campaigns and tracking, then hand you the keys. One payment, nothing recurring.',
    },
];

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

            {/* `max-w-2xl mx-auto sm:` — that trailing `sm:` was a truncated
                utility Tailwind silently ignored. */}
            <div className="py-8 sm:py-12">
                <div className="mx-auto max-w-2xl px-4 sm:px-0">
                    <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div className="p-6 text-center sm:p-8">
                            <span
                                className="mx-auto mb-4 inline-flex h-14 w-14 items-center justify-center rounded-full text-brand-darker"
                                style={{ backgroundColor: brandTint(12) }}
                            >
                                <RocketLaunchIcon className="h-7 w-7" aria-hidden="true" />
                            </span>
                            <h1 className="mb-2 text-2xl font-bold tracking-tight text-gray-900">
                                Get started in 30 seconds
                            </h1>
                            <p className="mx-auto mb-8 max-w-md text-gray-600">
                                Give us your website address. We read the site, learn your brand, and prepare
                                your first campaign.
                            </p>

                            <form onSubmit={handleSubmit} className="mx-auto max-w-lg text-left">
                                <label htmlFor="website_url" className="mb-1.5 block text-sm font-medium text-gray-700">
                                    Your website address
                                </label>
                                <div
                                    className={`flex items-center rounded-xl border-2 transition-all duration-200 ${
                                        errors.website_url
                                            ? 'border-red-500'
                                            : urlFocused
                                              ? 'border-brand-dark shadow-lg'
                                              : 'border-gray-200'
                                    }`}
                                >
                                    <GlobeAltIcon className="ml-4 h-5 w-5 shrink-0 text-gray-500" aria-hidden="true" />
                                    <input
                                        id="website_url"
                                        name="website_url"
                                        type="text"
                                        inputMode="url"
                                        autoComplete="url"
                                        value={data.website_url}
                                        onChange={(e) => setData('website_url', e.target.value)}
                                        onFocus={() => setUrlFocused(true)}
                                        onBlur={() => setUrlFocused(false)}
                                        placeholder="yourwebsite.com"
                                        aria-invalid={errors.website_url ? 'true' : undefined}
                                        aria-describedby={errors.website_url ? 'website_url-error' : undefined}
                                        className="min-w-0 flex-1 rounded-xl border-0 px-3 py-4 text-lg focus:outline-none focus:ring-0"
                                        autoFocus
                                    />
                                    {/*
                                        Disabled is a grey fill at 6.10:1 rather
                                        than bg-gray-300, which put white on
                                        2.0:1 — and this button starts disabled,
                                        so that was its resting state.
                                    */}
                                    <button
                                        type="submit"
                                        disabled={processing || !data.website_url.trim()}
                                        className="mr-2 inline-flex h-11 items-center gap-2 rounded-lg bg-brand-dark px-5 font-semibold text-white shadow-sm transition-colors hover:bg-brand-darker focus:outline-none focus:ring-2 focus:ring-brand-dark focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-600 disabled:shadow-none"
                                    >
                                        {processing ? (
                                            <>
                                                <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                </svg>
                                                Scanning…
                                            </>
                                        ) : (
                                            <>
                                                Go
                                                <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                                            </>
                                        )}
                                    </button>
                                </div>
                                {errors.website_url && (
                                    <p id="website_url-error" role="alert" className="mt-2 text-sm text-red-700">
                                        {errors.website_url}
                                    </p>
                                )}
                            </form>

                            {/*
                                The fork: ongoing management or one-and-done.
                                Intent only — payment happens at the plan step.

                                role="radiogroup" because that is what it is.
                                As two bare buttons, the selected one was
                                announced identically to the unselected one.
                            */}
                            <div
                                role="radiogroup"
                                aria-label="How would you like us to work with you?"
                                className="mx-auto mt-8 grid max-w-xl gap-3 text-left sm:grid-cols-2"
                            >
                                {SERVICES.map((option) => {
                                    const selected = data.service_type === option.value;

                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={selected}
                                            onClick={() => setData('service_type', option.value)}
                                            className={`rounded-lg border-2 p-4 text-left transition focus:outline-none focus:ring-2 focus:ring-brand-dark focus:ring-offset-2 ${
                                                selected
                                                    ? 'border-brand-dark'
                                                    : 'border-gray-200 bg-white hover:border-gray-300'
                                            }`}
                                            style={selected ? { backgroundColor: brandTint(10) } : undefined}
                                        >
                                            <p className="text-sm font-semibold text-gray-900">{option.title}</p>
                                            <p className="mt-1 text-xs leading-relaxed text-gray-600">{option.body}</p>
                                        </button>
                                    );
                                })}
                            </div>

                            <ul className="mx-auto mt-10 grid max-w-lg grid-cols-3 gap-4 text-center">
                                {STEPS.map((step) => (
                                    <li key={step.label} className="p-2">
                                        <step.icon
                                            className="mx-auto mb-2 h-6 w-6 text-brand-darker"
                                            aria-hidden="true"
                                        />
                                        <p className="text-xs leading-relaxed text-gray-600">{step.label}</p>
                                    </li>
                                ))}
                            </ul>

                            <p className="mt-6 text-sm text-gray-600">
                                Or{' '}
                                <a
                                    href={route('customers.create')}
                                    className="font-medium text-brand-darker hover:underline"
                                >
                                    set it up manually
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
