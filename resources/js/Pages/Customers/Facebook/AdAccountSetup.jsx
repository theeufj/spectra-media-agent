import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';

export default function AdAccountSetupPage({ auth, customer: initialCustomer, bm_configured }) {
    const { flash } = usePage().props;
    const [customer, setCustomer] = useState(initialCustomer);
    const [processing, setProcessing] = useState(false);
    const [successMessage, setSuccessMessage] = useState(flash?.success || null);
    const [errorMessage, setErrorMessage] = useState(flash?.error || null);

    useEffect(() => {
        if (flash?.success) {
            setSuccessMessage(flash.success);
            if (flash?.customer) setCustomer(flash.customer);
            setTimeout(() => setSuccessMessage(null), 8000);
        }
        if (flash?.error) setErrorMessage(flash.error);
    }, [flash]);

    const handleProvision = () => {
        setProcessing(true);
        setErrorMessage(null);
        router.post(route('customers.facebook.provision', customer.id), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    const isProvisioned = customer.facebook_ads_account_id && customer.facebook_bm_owned;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                    Facebook Ads Setup — {customer.name}
                </h2>
            }
        >
            <Head title="Facebook Ads Setup" />

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

                    {/* How it works */}
                    <div className="bg-blue-50 border border-blue-200 rounded-lg p-5">
                        <h3 className="font-semibold text-blue-900 mb-2">How Facebook Ads work here</h3>
                        <p className="text-sm text-blue-800">
                            We create and manage a Facebook ad account on your behalf through our Business Manager.
                            You never need to connect your personal Facebook account or grant any permissions —
                            just provide billing details after setup and we handle everything else.
                        </p>
                    </div>

                    {/* Platform BM not configured warning (admin only) */}
                    {!bm_configured && (
                        <div className="bg-amber-50 border border-amber-200 rounded-lg p-5">
                            <h3 className="font-semibold text-amber-900 mb-1">Platform setup required</h3>
                            <p className="text-sm text-amber-800">
                                <code className="bg-amber-100 px-1 rounded">FACEBOOK_BUSINESS_MANAGER_ID</code> and{' '}
                                <code className="bg-amber-100 px-1 rounded">FACEBOOK_SYSTEM_USER_TOKEN</code> are not
                                set in the server environment. Contact a platform administrator.
                            </p>
                        </div>
                    )}

                    {/* Step 1: Provision */}
                    <div className="bg-white shadow-sm sm:rounded-lg p-6">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Step 1 — Create Ad Account
                                </h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    We create a dedicated Facebook ad account linked to our Business Manager.
                                    No Facebook login required on your end.
                                </p>
                            </div>
                            {isProvisioned && (
                                <span className="ml-4 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    ✓ Ready
                                </span>
                            )}
                        </div>

                        {isProvisioned ? (
                            <p className="mt-3 text-sm text-gray-500">
                                Ad Account:{' '}
                                <code className="font-mono font-semibold text-gray-800">
                                    act_{customer.facebook_ads_account_id}
                                </code>
                            </p>
                        ) : (
                            <div className="mt-4">
                                <PrimaryButton
                                    onClick={handleProvision}
                                    disabled={processing || !bm_configured}
                                >
                                    {processing ? 'Creating account…' : 'Create Facebook Ad Account'}
                                </PrimaryButton>
                            </div>
                        )}
                    </div>

                    {/* Step 2: Page connection (shown once account exists) */}
                    {isProvisioned && (
                        <div className="bg-white shadow-sm sm:rounded-lg p-6">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold text-gray-900">
                                        Step 2 — Connect a Facebook Page
                                    </h3>
                                    <p className="mt-1 text-sm text-gray-600">
                                        Ads need a Facebook Page to run from. You can grant our Business Manager
                                        access to an existing Page, or we can create one for you.
                                    </p>
                                </div>
                                {customer.facebook_page_id && (
                                    <span className="ml-4 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        ✓ Connected
                                    </span>
                                )}
                            </div>

                            {customer.facebook_page_id ? (
                                <p className="mt-3 text-sm text-gray-500">
                                    Page: <span className="font-semibold text-gray-800">{customer.facebook_page_name}</span>
                                    {' '}(ID: {customer.facebook_page_id})
                                </p>
                            ) : (
                                <div className="mt-4 bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-600">
                                    Ask your account manager to add a Facebook Page to this account,
                                    or share your Page ID and we'll configure it for you.
                                </div>
                            )}
                        </div>
                    )}

                    {/* All ready */}
                    {isProvisioned && customer.facebook_page_id && (
                        <div className="bg-green-50 border border-green-200 rounded-lg p-5">
                            <h3 className="font-semibold text-green-900 mb-1">
                                ✅ Facebook Ads ready
                            </h3>
                            <p className="text-sm text-green-800">
                                Your Facebook ad account is configured and campaigns can now be deployed.
                                All billing will be managed through the ad account.
                            </p>
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
