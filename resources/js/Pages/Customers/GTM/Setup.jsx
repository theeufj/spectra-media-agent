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


/**
 * GTMSetupPage Component
 * Main page for GTM setup with Path A (Link) and Path B (Create) flows
 */
export default function GTMSetupPage({ auth, customer: initialCustomer }) {
    const { flash, errors: pageErrors } = usePage().props;
    const [customer, setCustomer] = useState(initialCustomer);
    const [mode, setMode] = useState('view'); // 'view', 'link', 'create'
    const [processing, setProcessing] = useState(false);
    const [successMessage, setSuccessMessage] = useState(flash?.success || null);
    const [errorMessage, setErrorMessage] = useState(flash?.error || null);

    useEffect(() => {
        if (flash?.success) {
            setSuccessMessage(flash.success);
            setTimeout(() => setSuccessMessage(null), 5000);
        }
        if (flash?.error) {
            setErrorMessage(flash.error);
        }
    }, [flash]);

    const handleLinkContainer = (formData) => {
        setProcessing(true);
        router.post(
            route('customers.gtm.link', customer.id),
            formData,
            {
                onSuccess: (page) => {
                    setMode('view');
                    setSuccessMessage('Container linked successfully!');
                    setCustomer(page.props.customer);
                },
                onError: (errors) => {
                    setErrorMessage('Failed to link container. Please check your container ID and permissions.');
                },
                onFinish: () => setProcessing(false),
            }
        );
    };

    const handleCreateContainer = (formData) => {
        setProcessing(true);
        router.post(
            route('customers.gtm.create', customer.id),
            formData,
            {
                onSuccess: (page) => {
                    setMode('view');
                    setSuccessMessage('Container created successfully! Please add the GTM code to your website.');
                    setCustomer(page.props.customer);
                },
                onError: (errors) => {
                    setErrorMessage('Failed to create container. Please try again.');
                },
                onFinish: () => setProcessing(false),
            }
        );
    };

    const handleRescan = () => {
        setProcessing(true);
        router.post(
            route('customers.gtm.rescan', customer.id),
            {},
            {
                onSuccess: (page) => {
                    setSuccessMessage('Website rescanned successfully!');
                    setCustomer(page.props.customer);
                },
                onError: (errors) => {
                    setErrorMessage('Failed to rescan website.');
                },
                onFinish: () => setProcessing(false),
            }
        );
    };

    const handleVerify = () => {
        setProcessing(true);
        router.post(
            route('customers.gtm.verify', customer.id),
            {},
            {
                onSuccess: (page) => {
                    setSuccessMessage('Container verified successfully!');
                    setCustomer(page.props.customer);
                },
                onError: (errors) => {
                    setErrorMessage('Verification failed. Please check container permissions.');
                },
                onFinish: () => setProcessing(false),
            }
        );
    };

    const renderContent = () => {
        if (mode === 'link') {
            return (
                <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div className="p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">
                            Link Existing GTM Container
                        </h3>
                        <GTMLinkForm
                            customer={customer}
                            onSubmit={handleLinkContainer}
                            onCancel={() => setMode('view')}
                            processing={processing}
                            errors={pageErrors}
                        />
                    </div>
                </div>
            );
        }

        if (mode === 'create') {
            return (
                <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div className="p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">
                            Create New GTM Container
                        </h3>
                        <GTMCreateForm
                            customer={customer}
                            onSubmit={handleCreateContainer}
                            onCancel={() => setMode('view')}
                            processing={processing}
                            errors={pageErrors}
                        />
                    </div>
                </div>
            );
        }

        // View mode
        return (
            <>
                <GTMStatusCard customer={customer} onRescan={handleRescan} />

                {!customer.gtm_container_id && (
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                Get Started with Google Tag Manager
                            </h3>
                            <p className="text-gray-600 mb-6">
                                Choose how you'd like to set up GTM for conversion tracking:
                            </p>

                            <div className="grid md:grid-cols-2 gap-6">
                                {/* Path A: Link Existing */}
                                <div className="border border-gray-200 rounded-lg p-6 hover:border-indigo-300 transition">
                                    <div className="flex items-center mb-3">
                                        <div className="bg-indigo-100 rounded-full p-2 mr-3">
                                            <svg
                                                className="w-6 h-6 text-indigo-600"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    strokeWidth={2}
                                                    d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"
                                                />
                                            </svg>
                                        </div>
                                        <h4 className="text-lg font-semibold text-gray-900">
                                            Path A: Link Existing Container
                                        </h4>
                                    </div>
                                    <p className="text-sm text-gray-600 mb-4">
                                        Already have GTM installed? Link your existing container to enable conversion tracking.
                                    </p>
                                    <ul className="text-sm text-gray-600 space-y-2 mb-4">
                                        <li className="flex items-start">
                                            <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                            </svg>
                                            Faster setup (2-3 minutes)
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                            </svg>
                                            No code changes needed
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                            </svg>
                                            Works with existing tags
                                        </li>
                                    </ul>
                                    <PrimaryButton onClick={() => setMode('link')}>
                                        Link Container
                                    </PrimaryButton>
                                </div>

                                {/* Path B: Create New */}
                                <div className="border border-gray-200 rounded-lg p-6 hover:border-indigo-300 transition">
                                    <div className="flex items-center mb-3">
                                        <div className="bg-green-100 rounded-full p-2 mr-3">
                                            <svg
                                                className="w-6 h-6 text-green-600"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    strokeWidth={2}
                                                    d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                                                />
                                            </svg>
                                        </div>
                                        <h4 className="text-lg font-semibold text-gray-900">
                                            Path B: Create New Container
                                        </h4>
                                    </div>
                                    <p className="text-sm text-gray-600 mb-4">
                                        Don't have GTM yet? We'll create and configure a new container for you.
                                    </p>
                                    <ul className="text-sm text-gray-600 space-y-2 mb-4">
                                        <li className="flex items-start">
                                            <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                            </svg>
                                            Fresh start with best practices
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                            </svg>
                                            Pre-configured for conversions
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="w-5 h-5 text-yellow-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                            </svg>
                                            Requires adding code to site
                                        </li>
                                    </ul>
                                    <SecondaryButton onClick={() => setMode('create')}>
                                        Create Container
                                    </SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {customer.gtm_container_id && !customer.gtm_last_verified && (
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                Verify Container Access
                            </h3>
                            <p className="text-gray-600 mb-4">
                                Click below to verify that we have proper access to manage your GTM container.
                            </p>
                            <PrimaryButton onClick={handleVerify} disabled={processing}>
                                {processing ? 'Verifying...' : 'Verify Access'}
                            </PrimaryButton>
                        </div>
                    </div>
                )}

                {customer.gtm_installed && customer.gtm_last_verified && (
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                GTM Setup Complete
                            </h3>
                            <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                                <p className="text-green-800">
                                    ✅ Your GTM container is successfully linked and verified. 
                                    Conversion tracking tags will be automatically managed for your campaigns.
                                </p>
                            </div>
                        </div>
                    </div>
                )}
            </>
        );
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                    Google Tag Manager Setup - {customer.name}
                </h2>
            }
        >
            <Head title="GTM Setup" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {successMessage && <GTMSuccessAlert message={successMessage} />}
                    {errorMessage && <GTMErrorAlert message={errorMessage} />}

                    {renderContent()}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
