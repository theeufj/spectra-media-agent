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
                    className="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
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

export default function GTMSetupPage({ auth, customer: initialCustomer, snippet: initialSnippet }) {
    const { flash } = usePage().props;
    const [customer, setCustomer] = useState(initialCustomer);
    const [snippet, setSnippet] = useState(initialSnippet);
    const [processing, setProcessing] = useState(false);
    const [successMessage, setSuccessMessage] = useState(flash?.success || null);
    const [errorMessage, setErrorMessage] = useState(flash?.error || null);

    useEffect(() => {
        if (flash?.success) {
            setSuccessMessage(flash.success);
            if (flash?.snippet) setSnippet(flash.snippet);
            if (flash?.customer) setCustomer(flash.customer);
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
