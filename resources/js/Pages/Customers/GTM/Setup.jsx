import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

function CopyBlock({ label, code }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard.writeText(code).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="mb-4">
            <div className="flex items-center justify-between mb-1">
                <span className="text-sm font-medium text-gray-700">{label}</span>
                <button
                    onClick={copy}
                    className="text-xs text-flame-orange-600 hover:text-flame-orange-800 font-medium"
                >
                    {copied ? '✓ Copied' : 'Copy'}
                </button>
            </div>
            <pre className="bg-gray-900 text-green-300 text-xs rounded-lg p-4 overflow-x-auto whitespace-pre-wrap break-all">
                {code}
            </pre>
        </div>
    );
}

function ExistingTagsInfo() {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="bg-blue-50 border border-blue-200 shadow-sm sm:rounded-lg p-6">
            <button
                onClick={() => setExpanded(!expanded)}
                className="flex items-center gap-2 w-full text-left"
            >
                <svg className="h-5 w-5 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
                <span className="text-sm font-semibold text-blue-900">
                    Already have Google tags on your website?
                </span>
                <svg className={`h-4 w-4 text-blue-500 ml-auto transition-transform ${expanded ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {expanded && (
                <div className="mt-4 space-y-3 text-sm text-blue-800">
                    <p>
                        If your website already has Google tags installed — such as Google Tag Manager, Google Analytics, or Google Ads conversion tracking — that's completely fine. Here's how our tracking works with your existing setup:
                    </p>
                    <div className="space-y-2">
                        <p>
                            <strong>Google Tag Manager (GTM):</strong> Multiple GTM containers can run on the same page without conflict. Our container handles campaign conversion tracking independently from your existing container.
                        </p>
                        <p>
                            <strong>Google Analytics (GA4):</strong> Your existing analytics will continue working as normal. Our tracking is focused on ad conversion events and doesn't duplicate analytics data.
                        </p>
                        <p>
                            <strong>Google Ads conversion tags:</strong> If you have manually installed Google Ads conversion tags (e.g., a global site tag or gtag.js snippet), we recommend removing them after our tracking is verified. Running both could count conversions twice.
                        </p>
                        <p>
                            <strong>Facebook Pixel / other platforms:</strong> No changes needed — our container is completely separate.
                        </p>
                    </div>
                    <p className="text-blue-700 italic">
                        Just proceed with the steps below and add our snippet alongside your existing tags.
                    </p>
                </div>
            )}
        </div>
    );
}

export default function GTMSetupPage({ auth, customer: initialCustomer, snippet: initialSnippet, existingTags }) {
    const { flash } = usePage().props;
    const [customer, setCustomer] = useState(initialCustomer);
    const [snippet, setSnippet] = useState(initialSnippet);
    const [processing, setProcessing] = useState(false);
    const [successMessage, setSuccessMessage] = useState(flash?.success || null);
    const [errorMessage, setErrorMessage] = useState(flash?.error || null);

    // Sync state from props when Inertia re-renders with fresh data after redirect
    useEffect(() => {
        setCustomer(initialCustomer);
        setSnippet(initialSnippet);
    }, [initialCustomer, initialSnippet]);

    useEffect(() => {
        if (flash?.success) {
            setSuccessMessage(flash.success);
            setTimeout(() => setSuccessMessage(null), 6000);
        }
        if (flash?.error) setErrorMessage(flash.error);
    }, [flash]);

    const handleProvision = () => {
        setProcessing(true);
        router.post(route('customers.gtm.provision', customer.id), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    const handleVerify = () => {
        setProcessing(true);
        setErrorMessage(null);
        router.post(route('customers.gtm.verify', customer.id), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    const handleRescan = () => {
        setProcessing(true);
        router.post(route('customers.gtm.rescan', customer.id), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                    Tracking Setup — {customer.name}
                </h2>
            }
        >
            <Head title="Tracking Setup" />

            <div className="py-12">
                <div className="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

                    {successMessage && (
                        <div className="bg-green-50 border border-green-200 rounded-lg p-4 text-green-800">
                            {successMessage}
                        </div>
                    )}
                    {errorMessage && (
                        <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-red-800">
                            {errorMessage}
                        </div>
                    )}

                    {/* Existing Google Tags Guidance */}
                    {(customer.gtm_detected || existingTags) && (
                        <div className="bg-amber-50 border border-amber-200 shadow-sm sm:rounded-lg p-6">
                            <div className="flex items-start gap-3">
                                <svg className="h-6 w-6 text-amber-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                </svg>
                                <div>
                                    <h3 className="text-lg font-semibold text-amber-900">
                                        Existing Google Tags Detected
                                    </h3>
                                    {existingTags?.detected_container_id && (
                                        <p className="mt-1 text-sm text-amber-800">
                                            We found container <code className="font-mono font-semibold bg-amber-100 px-1 rounded">{existingTags.detected_container_id}</code> on your website.
                                        </p>
                                    )}
                                    <p className="mt-2 text-sm text-amber-800">
                                        No worries — our tracking works alongside your existing tags. Here's what you need to know:
                                    </p>
                                    <ul className="mt-3 space-y-2 text-sm text-amber-800">
                                        <li className="flex items-start gap-2">
                                            <span className="font-bold text-amber-600 mt-px">•</span>
                                            <span><strong>Existing GTM container:</strong> You can keep your current container in place. Our container runs independently and won't interfere with your existing tags or tracking.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="font-bold text-amber-600 mt-px">•</span>
                                            <span><strong>Google Analytics (GA4):</strong> If you have GA4 installed directly (not through GTM), it will continue to work normally. Our container only handles conversion tracking for your ad campaigns.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="font-bold text-amber-600 mt-px">•</span>
                                            <span><strong>Google Ads tags:</strong> If you already have Google Ads conversion tags on your site, we recommend removing them once our tracking is verified to avoid duplicate conversion counting.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="font-bold text-amber-600 mt-px">•</span>
                                            <span><strong>Other tags (Facebook Pixel, etc.):</strong> No changes needed. Our container is completely separate and won't affect any other tracking you have in place.</span>
                                        </li>
                                    </ul>
                                    <p className="mt-3 text-sm text-amber-700 italic">
                                        Continue with the steps below to set up conversion tracking — just add our snippet alongside your existing tags.
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* General guidance when no tags detected but helpful context */}
                    {!customer.gtm_detected && !existingTags && !customer.gtm_container_id && (
                        <ExistingTagsInfo />
                    )}

                    {/* Step 1: Provision */}
                    <div className="bg-white shadow-sm sm:rounded-lg p-6">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Step 1 — Enable Conversion Tracking
                                </h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    We create and manage a Google Tag Manager container for your site. No Google account connection required.
                                </p>
                            </div>
                            {customer.gtm_container_id && (
                                <span className="ml-4 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    ✓ Provisioned
                                </span>
                            )}
                        </div>

                        {!customer.gtm_container_id ? (
                            <div className="mt-4">
                                <PrimaryButton onClick={handleProvision} disabled={processing}>
                                    {processing ? 'Setting up…' : 'Set up tracking'}
                                </PrimaryButton>
                            </div>
                        ) : (
                            <p className="mt-3 text-sm text-gray-500">
                                Container ID: <code className="font-mono font-semibold text-gray-800">{customer.gtm_container_id}</code>
                            </p>
                        )}
                    </div>

                    {/* Step 2: Install snippet */}
                    {(snippet || customer.gtm_container_id) && (
                        <div className="bg-white shadow-sm sm:rounded-lg p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-1">
                                Step 2 — Add the snippet to your website
                            </h3>
                            <p className="text-sm text-gray-600 mb-5">
                                Paste these two snippets into your website. The first goes in the{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">&lt;head&gt;</code>, the
                                second immediately after the opening{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">&lt;body&gt;</code> tag.
                            </p>

                            {snippet ? (
                                <>
                                    <CopyBlock label="Paste inside <head>" code={snippet.head} />
                                    <CopyBlock label="Paste after opening <body>" code={snippet.body} />
                                </>
                            ) : (
                                <p className="text-sm text-gray-500">Reload the page to see your snippet.</p>
                            )}
                        </div>
                    )}

                    {/* Step 3: Verify */}
                    {customer.gtm_container_id && (
                        <div className="bg-white shadow-sm sm:rounded-lg p-6">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold text-gray-900">
                                        Step 3 — Verify installation
                                    </h3>
                                    <p className="mt-1 text-sm text-gray-600">
                                        Once you've added the snippet, click below and we'll scan your site to confirm it's live.
                                    </p>
                                </div>
                                {customer.gtm_installed && (
                                    <span className="ml-4 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        ✓ Verified
                                    </span>
                                )}
                            </div>

                            {customer.gtm_installed ? (
                                <div className="mt-4 bg-green-50 border border-green-200 rounded-lg p-4 text-sm text-green-800">
                                    Snippet confirmed on your website. Conversion tracking is active and all campaigns
                                    will report conversions automatically.
                                </div>
                            ) : (
                                <div className="mt-4 flex gap-3">
                                    <PrimaryButton onClick={handleVerify} disabled={processing || !customer.website}>
                                        {processing ? 'Scanning…' : 'Verify snippet'}
                                    </PrimaryButton>
                                    <SecondaryButton onClick={handleRescan} disabled={processing}>
                                        Re-scan site
                                    </SecondaryButton>
                                </div>
                            )}

                            {!customer.website && (
                                <p className="mt-2 text-xs text-amber-600">
                                    Add your website URL in your profile settings to enable verification.
                                </p>
                            )}

                            {customer.gtm_last_verified && (
                                <p className="mt-3 text-xs text-gray-400">
                                    Last verified: {new Date(customer.gtm_last_verified).toLocaleString()}
                                </p>
                            )}
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
