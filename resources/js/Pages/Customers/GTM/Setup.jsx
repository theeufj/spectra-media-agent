import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GTMStatusCard from '@/Components/GTM/GTMStatusCard';
import GTMLinkForm from '@/Components/GTM/GTMLinkForm';
import GTMCreateForm from '@/Components/GTM/GTMCreateForm';
import GTMErrorAlert from '@/Components/GTM/GTMErrorAlert';
import GTMSuccessAlert from '@/Components/GTM/GTMSuccessAlert';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

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
