import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

export default function AdAccountSetupPage({ auth, customer: initialCustomer, bm_configured }) {
    const { flash } = usePage().props;
    const [customer, setCustomer] = useState(initialCustomer);
    const [adAccountId, setAdAccountId] = useState('');
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

    const handleAssign = (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrorMessage(null);
        router.post(route('customers.facebook.assign', customer.id), { ad_account_id: adAccountId.replace('act_', '') }, {
            onFinish: () => setProcessing(false),
        });
    };

    const handleVerify = () => {
        setProcessing(true);
        setErrorMessage(null);
        router.post(route('customers.facebook.verify', customer.id), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    const isLinked = customer.facebook_ads_account_id && customer.facebook_bm_owned;

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
                        <h3 className="font-semibold text-blue-900 mb-2">How this works</h3>
                        <p className="text-sm text-blue-800">
                            We manage a Facebook ad account on your behalf through our Business Manager.
                            The client never needs to connect their personal Facebook account — we handle
                            all ad operations using our platform token.
                        </p>
                    </div>

                    {/* BM not configured warning */}
                    {!bm_configured && (
                        <div className="bg-amber-50 border border-amber-200 rounded-lg p-5">
                            <h3 className="font-semibold text-amber-900 mb-1">Platform not configured</h3>
                            <p className="text-sm text-amber-800">
                                <code className="bg-amber-100 px-1 rounded">FACEBOOK_BUSINESS_MANAGER_ID</code> and{' '}
                                <code className="bg-amber-100 px-1 rounded">FACEBOOK_SYSTEM_USER_TOKEN</code> must be
                                set on the server.
                            </p>
                        </div>
                    )}

                    {/* Step 1: Create account in BM */}
                    <div className="bg-white shadow-sm sm:rounded-lg p-6">
                        <div className="flex items-start justify-between mb-3">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Step 1 — Create ad account in Business Manager
                            </h3>
                            {isLinked && (
                                <span className="ml-4 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    ✓ Linked
                                </span>
                            )}
                        </div>

                        <ol className="text-sm text-gray-600 space-y-2 mb-4 list-decimal list-inside">
                            <li>Go to <strong>business.facebook.com → Business Settings → Accounts → Ad Accounts</strong></li>
                            <li>Click <strong>Add → Create a new ad account</strong></li>
                            <li>In the account, go to <strong>Ad Account Roles</strong> and add the System User as <strong>Admin</strong></li>
                            <li>Copy the numeric account ID shown as <code className="bg-gray-100 px-1 rounded">act_XXXXXXXXX</code></li>
                        </ol>
                    </div>

                    {/* Step 2: Link the account */}
                    <div className="bg-white shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-3">
                            Step 2 — Link the account to this customer
                        </h3>

                        {isLinked ? (
                            <div className="space-y-3">
                                <p className="text-sm text-gray-600">
                                    Linked account:{' '}
                                    <code className="font-mono font-semibold text-gray-900">
                                        act_{customer.facebook_ads_account_id}
                                    </code>
                                </p>
                                <SecondaryButton onClick={handleVerify} disabled={processing}>
                                    {processing ? 'Checking…' : 'Verify access'}
                                </SecondaryButton>
                            </div>
                        ) : (
                            <form onSubmit={handleAssign} className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Ad Account ID
                                    </label>
                                    <div className="flex rounded-md shadow-sm">
                                        <span className="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-sm">
                                            act_
                                        </span>
                                        <input
                                            type="text"
                                            value={adAccountId}
                                            onChange={e => setAdAccountId(e.target.value.replace(/\D/g, ''))}
                                            placeholder="123456789012345"
                                            className="flex-1 min-w-0 block px-3 py-2 rounded-none rounded-r-md border border-gray-300 focus:ring-flame-orange-500 focus:border-flame-orange-500 text-sm font-mono"
                                            required
                                        />
                                    </div>
                                    <p className="mt-1 text-xs text-gray-500">
                                        Paste the full <code>act_XXXX</code> value and we'll strip the prefix automatically.
                                    </p>
                                </div>
                                <PrimaryButton type="submit" disabled={processing || !bm_configured || !adAccountId}>
                                    {processing ? 'Linking…' : 'Link Ad Account'}
                                </PrimaryButton>
                            </form>
                        )}
                    </div>

                    {/* Step 3: Page */}
                    {isLinked && (
                        <div className="bg-white shadow-sm sm:rounded-lg p-6">
                            <div className="flex items-start justify-between mb-3">
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Step 3 — Facebook Page
                                </h3>
                                {customer.facebook_page_id && (
                                    <span className="ml-4 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        ✓ Connected
                                    </span>
                                )}
                            </div>

                            {customer.facebook_page_id ? (
                                <p className="text-sm text-gray-600">
                                    Page: <span className="font-semibold">{customer.facebook_page_name}</span>{' '}
                                    (ID: {customer.facebook_page_id})
                                </p>
                            ) : (
                                <p className="text-sm text-gray-600">
                                    Add the Platform System User as an editor on the client's Facebook Page,
                                    then enter the Page ID via the customer profile settings.
                                </p>
                            )}
                        </div>
                    )}

                    {/* All ready */}
                    {isLinked && customer.facebook_page_id && (
                        <div className="bg-green-50 border border-green-200 rounded-lg p-5">
                            <h3 className="font-semibold text-green-900 mb-1">✅ Facebook Ads ready</h3>
                            <p className="text-sm text-green-800">
                                Ad account is linked and the platform System User has access.
                                Campaigns can now be deployed — no client OAuth required.
                            </p>
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
