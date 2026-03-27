import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SideNav from './SideNav';

function AccountForm({ account, onClose, isEdit }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: account?.name || '',
        google_customer_id: account?.google_customer_id || '',
        refresh_token: '',
        notes: account?.notes || '',
        is_active: account?.is_active ?? false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(route('admin.mcc-accounts.update', account.id), { onSuccess: onClose });
        } else {
            post(route('admin.mcc-accounts.store'), { onSuccess: onClose });
        }
    };

    return (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div className="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4">
                <div className="px-6 py-4 border-b border-gray-200">
                    <h3 className="text-lg font-semibold text-gray-900">
                        {isEdit ? 'Edit MCC Account' : 'Add MCC Account'}
                    </h3>
                </div>
                <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g., Primary MCC"
                            className="w-full border-gray-300 rounded-md shadow-sm focus:ring-flame-orange-500 focus:border-flame-orange-500"
                        />
                        {errors.name && <p className="text-red-500 text-xs mt-1">{errors.name}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Google Customer ID</label>
                        <input
                            type="text"
                            value={data.google_customer_id}
                            onChange={(e) => setData('google_customer_id', e.target.value)}
                            placeholder="e.g., 5584506211 or 558-450-6211"
                            className="w-full border-gray-300 rounded-md shadow-sm focus:ring-flame-orange-500 focus:border-flame-orange-500"
                        />
                        <p className="text-xs text-gray-500 mt-1">The MCC account ID from Google Ads. Dashes are stripped automatically.</p>
                        {errors.google_customer_id && <p className="text-red-500 text-xs mt-1">{errors.google_customer_id}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            OAuth Refresh Token {isEdit && <span className="text-gray-400">(leave blank to keep current)</span>}
                        </label>
                        <textarea
                            value={data.refresh_token}
                            onChange={(e) => setData('refresh_token', e.target.value)}
                            placeholder={isEdit ? '••••••••' : 'Paste the refresh token here'}
                            rows={2}
                            className="w-full border-gray-300 rounded-md shadow-sm focus:ring-flame-orange-500 focus:border-flame-orange-500 font-mono text-xs"
                        />
                        {errors.refresh_token && <p className="text-red-500 text-xs mt-1">{errors.refresh_token}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            placeholder="Optional notes about this account..."
                            rows={2}
                            className="w-full border-gray-300 rounded-md shadow-sm focus:ring-flame-orange-500 focus:border-flame-orange-500"
                        />
                    </div>

                    {!isEdit && (
                        <div className="flex items-center">
                            <input
                                type="checkbox"
                                id="is_active"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="rounded border-gray-300 text-flame-orange-500 focus:ring-flame-orange-500"
                            />
                            <label htmlFor="is_active" className="ml-2 text-sm text-gray-700">
                                Set as active MCC account
                            </label>
                        </div>
                    )}

                    <div className="flex justify-end gap-3 pt-4 border-t border-gray-200">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-500 rounded-md hover:bg-flame-orange-600 disabled:opacity-50"
                        >
                            {processing ? 'Saving...' : (isEdit ? 'Update' : 'Add Account')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function MccAccounts({ accounts, usingEnvFallback, envCustomerId }) {
    const [showForm, setShowForm] = useState(false);
    const [editAccount, setEditAccount] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(null);

    const handleActivate = (account) => {
        if (confirm(`Set "${account.name}" as the active MCC account? All new API calls will use this account.`)) {
            router.post(route('admin.mcc-accounts.activate', account.id));
        }
    };

    const handleDelete = (account) => {
        router.delete(route('admin.mcc-accounts.destroy', account.id));
        setConfirmDelete(null);
    };

    const formatCustomerId = (id) => {
        if (!id || id.length !== 10) return id;
        return `${id.slice(0, 3)}-${id.slice(3, 6)}-${id.slice(6)}`;
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Admin - MCC Accounts
                </h2>
            }
        >
            <Head title="Admin - MCC Accounts" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-4xl mx-auto">
                        {/* Header */}
                        <div className="bg-white shadow-md rounded-lg overflow-hidden">
                            <div className="px-6 py-4 bg-gradient-to-r from-purple-600 to-flame-orange-600 flex items-center justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold text-white">Google Ads MCC Accounts</h3>
                                    <p className="text-sm text-purple-100 mt-1">Manage Manager (MCC) accounts used to create and manage customer sub-accounts</p>
                                </div>
                                <button
                                    onClick={() => { setEditAccount(null); setShowForm(true); }}
                                    className="px-4 py-2 text-sm font-medium text-flame-orange-600 bg-white rounded-md hover:bg-gray-50 shadow"
                                >
                                    + Add MCC Account
                                </button>
                            </div>

                            <div className="p-6">
                                {/* Env fallback notice */}
                                {usingEnvFallback && (
                                    <div className="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                        <div className="flex items-start">
                                            <svg className="w-5 h-5 text-yellow-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                            </svg>
                                            <div className="ml-3">
                                                <h4 className="text-sm font-medium text-yellow-800">Using Environment Variable Fallback</h4>
                                                <p className="text-sm text-yellow-700 mt-1">
                                                    No MCC accounts are configured in the database. Currently using the MCC from environment variables
                                                    (Customer ID: <span className="font-mono">{formatCustomerId(envCustomerId)}</span>).
                                                    Add an MCC account below to manage it from this panel instead.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Account list */}
                                {accounts.length === 0 && !usingEnvFallback ? (
                                    <div className="text-center py-12">
                                        <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                        <h3 className="mt-2 text-sm font-medium text-gray-900">No MCC Accounts</h3>
                                        <p className="mt-1 text-sm text-gray-500">
                                            Add your Google Ads MCC account to start managing customer sub-accounts.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        {accounts.map((account) => (
                                            <div
                                                key={account.id}
                                                className={`border rounded-lg p-4 ${
                                                    account.is_active
                                                        ? 'border-green-300 bg-green-50'
                                                        : 'border-gray-200 bg-white'
                                                }`}
                                            >
                                                <div className="flex items-start justify-between">
                                                    <div className="flex-1">
                                                        <div className="flex items-center gap-3">
                                                            <h4 className="text-base font-semibold text-gray-900">{account.name}</h4>
                                                            {account.is_active && (
                                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                                    Active
                                                                </span>
                                                            )}
                                                        </div>
                                                        <div className="mt-1 flex items-center gap-4 text-sm text-gray-500">
                                                            <span className="font-mono">{formatCustomerId(account.google_customer_id)}</span>
                                                            {account.has_refresh_token ? (
                                                                <span className="flex items-center text-green-600">
                                                                    <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                                                    </svg>
                                                                    Token configured
                                                                </span>
                                                            ) : (
                                                                <span className="flex items-center text-red-500">
                                                                    <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                                    </svg>
                                                                    No token
                                                                </span>
                                                            )}
                                                        </div>
                                                        {account.notes && (
                                                            <p className="mt-2 text-sm text-gray-600">{account.notes}</p>
                                                        )}
                                                    </div>
                                                    <div className="flex items-center gap-2 ml-4">
                                                        {!account.is_active && (
                                                            <button
                                                                onClick={() => handleActivate(account)}
                                                                className="px-3 py-1.5 text-xs font-medium text-green-700 bg-green-100 rounded-md hover:bg-green-200"
                                                            >
                                                                Set Active
                                                            </button>
                                                        )}
                                                        <button
                                                            onClick={() => { setEditAccount(account); setShowForm(true); }}
                                                            className="px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                                                        >
                                                            Edit
                                                        </button>
                                                        {!account.is_active && (
                                                            confirmDelete === account.id ? (
                                                                <div className="flex items-center gap-1">
                                                                    <button
                                                                        onClick={() => handleDelete(account)}
                                                                        className="px-3 py-1.5 text-xs font-medium text-white bg-red-600 rounded-md hover:bg-red-700"
                                                                    >
                                                                        Confirm
                                                                    </button>
                                                                    <button
                                                                        onClick={() => setConfirmDelete(null)}
                                                                        className="px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                                                                    >
                                                                        Cancel
                                                                    </button>
                                                                </div>
                                                            ) : (
                                                                <button
                                                                    onClick={() => setConfirmDelete(account.id)}
                                                                    className="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 rounded-md hover:bg-red-100"
                                                                >
                                                                    Delete
                                                                </button>
                                                            )
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {showForm && (
                <AccountForm
                    account={editAccount}
                    isEdit={!!editAccount}
                    onClose={() => { setShowForm(false); setEditAccount(null); }}
                />
            )}
        </AuthenticatedLayout>
    );
}
