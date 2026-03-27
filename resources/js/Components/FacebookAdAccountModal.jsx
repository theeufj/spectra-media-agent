import React, { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import Modal from '@/Components/Modal';

export default function FacebookAdAccountModal({ show, onClose, customer: initialCustomer, bmConfigured }) {
    const { flash } = usePage().props;
    const [customer, setCustomer] = useState(initialCustomer);
    const [adAccountId, setAdAccountId] = useState('');
    const [processing, setProcessing] = useState(false);
    const [successMessage, setSuccessMessage] = useState(null);
    const [errorMessage, setErrorMessage] = useState(null);

    // Sync customer if parent refreshes (e.g. Inertia partial reload)
    useEffect(() => {
        setCustomer(initialCustomer);
    }, [initialCustomer]);

    useEffect(() => {
        if (show) {
            setAdAccountId('');
            setSuccessMessage(null);
            setErrorMessage(null);
        }
    }, [show]);

    useEffect(() => {
        if (flash?.success) {
            setSuccessMessage(flash.success);
            if (flash?.customer) setCustomer(flash.customer);
        }
        if (flash?.error) {
            setErrorMessage(flash.error);
        }
    }, [flash]);

    const isLinked = customer.facebook_ads_account_id && customer.facebook_bm_owned;

    const handleAssign = (e) => {
        e.preventDefault();
        setProcessing(true);
        setSuccessMessage(null);
        setErrorMessage(null);
        router.post(
            route('customers.facebook.assign', customer.id),
            { ad_account_id: adAccountId.replace(/^act_/, '') },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAdAccountId('');
                },
                onError: (errors) => {
                    setErrorMessage(errors.message || errors.ad_account_id || 'Failed to assign ad account.');
                },
                onFinish: () => setProcessing(false),
            }
        );
    };

    const handleVerify = () => {
        setProcessing(true);
        setSuccessMessage(null);
        setErrorMessage(null);
        router.post(
            route('customers.facebook.verify', customer.id),
            {},
            {
                preserveScroll: true,
                onError: (errors) => {
                    setErrorMessage(errors.message || 'Verification failed.');
                },
                onFinish: () => setProcessing(false),
            }
        );
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <div className="p-6">
                {/* Header */}
                <div className="flex items-center justify-between mb-5">
                    <div className="flex items-center gap-3">
                        <div className="w-9 h-9 bg-blue-600 rounded-lg flex items-center justify-center">
                            <svg className="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold text-gray-900">Facebook Ad Account Setup</h2>
                            <p className="text-sm text-gray-500">{customer.name}</p>
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        className="text-gray-400 hover:text-gray-600 transition"
                        aria-label="Close"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Alerts */}
                {successMessage && (
                    <div className="mb-4 bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-800">
                        {successMessage}
                    </div>
                )}
                {errorMessage && (
                    <div className="mb-4 bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800">
                        {errorMessage}
                    </div>
                )}

                {/* BM not configured warning */}
                {!bmConfigured && (
                    <div className="mb-4 bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
                        <strong className="block mb-1">Platform BM not configured</strong>
                        <code className="text-xs">FACEBOOK_BUSINESS_MANAGER_ID</code> and{' '}
                        <code className="text-xs">FACEBOOK_SYSTEM_USER_TOKEN</code> must be set in the server environment before account assignment will work.
                    </div>
                )}

                {/* Currently linked */}
                {isLinked && (
                    <div className="mb-5 bg-green-50 border border-green-200 rounded-lg p-4 flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium text-green-900">Ad account linked via Business Manager</p>
                            <p className="mt-0.5 text-sm font-mono text-green-700">act_{customer.facebook_ads_account_id}</p>
                        </div>
                        <button
                            onClick={handleVerify}
                            disabled={processing || !bmConfigured}
                            className="ml-4 px-3 py-1.5 text-sm bg-white border border-green-300 text-green-700 rounded-md hover:bg-green-50 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            {processing ? 'Checking…' : 'Verify Access'}
                        </button>
                    </div>
                )}

                {/* Setup instructions */}
                <div className="space-y-4 mb-5">
                    <h3 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Setup Steps</h3>

                    <div className="flex gap-3">
                        <div className="flex-shrink-0 w-6 h-6 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex items-center justify-center">1</div>
                        <div>
                            <p className="text-sm font-medium text-gray-800">Create the ad account in Business Manager</p>
                            <p className="text-xs text-gray-500 mt-0.5">
                                Go to <span className="font-mono">business.facebook.com</span> → Accounts → Ad Accounts → Create.
                                Name it after the client.
                            </p>
                        </div>
                    </div>

                    <div className="flex gap-3">
                        <div className="flex-shrink-0 w-6 h-6 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex items-center justify-center">2</div>
                        <div>
                            <p className="text-sm font-medium text-gray-800">Add the Platform System User as Admin</p>
                            <p className="text-xs text-gray-500 mt-0.5">
                                In the ad account settings → People & Partners → Add People → select the platform
                                system user and assign <strong>Admin</strong> role.
                            </p>
                        </div>
                    </div>

                    <div className="flex gap-3">
                        <div className="flex-shrink-0 w-6 h-6 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex items-center justify-center">3</div>
                        <div>
                            <p className="text-sm font-medium text-gray-800">Enter the numeric account ID below</p>
                            <p className="text-xs text-gray-500 mt-0.5">
                                Find the account ID in the URL: <span className="font-mono">act=<strong>1234567890</strong></span>.
                                Enter the digits only — no <span className="font-mono">act_</span> prefix needed.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Assign form */}
                <form onSubmit={handleAssign}>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                        {isLinked ? 'Replace linked account' : 'Ad Account ID'}
                    </label>
                    <div className="flex gap-2">
                        <div className="relative flex-1">
                            <span className="absolute inset-y-0 left-3 flex items-center text-gray-400 text-sm pointer-events-none">act_</span>
                            <input
                                type="text"
                                inputMode="numeric"
                                value={adAccountId}
                                onChange={(e) => setAdAccountId(e.target.value.replace(/\D/g, ''))}
                                placeholder="1991968421347247"
                                className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md text-sm font-mono focus:ring-blue-500 focus:border-blue-500"
                                disabled={processing || !bmConfigured}
                                required
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={processing || !adAccountId || !bmConfigured}
                            className="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition whitespace-nowrap"
                        >
                            {processing ? 'Linking…' : isLinked ? 'Reassign' : 'Link Account'}
                        </button>
                    </div>
                    <p className="mt-1.5 text-xs text-gray-400">
                        Digits only — the platform will verify it has System User access before saving.
                    </p>
                </form>

                {/* Footer */}
                <div className="mt-6 pt-4 border-t border-gray-100 flex justify-end">
                    <button
                        onClick={onClose}
                        className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 transition"
                    >
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    );
}
