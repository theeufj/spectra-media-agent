import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage, useForm } from '@inertiajs/react';
import SideNav from './SideNav';
import FacebookAdAccountModal from '@/Components/FacebookAdAccountModal';

export default function CustomerDetail({ auth, bm_configured }) {
    const { customer } = usePage().props;
    const [showFacebookModal, setShowFacebookModal] = useState(false);
    const [editingFbAccount, setEditingFbAccount] = useState(false);
    const [editingMsAccount, setEditingMsAccount] = useState(false);
    const [editingGoogleAccount, setEditingGoogleAccount] = useState(false);

    const googleForm = useForm({
        google_ads_customer_id: customer.google_ads_customer_id || '',
        google_ads_manager_customer_id: customer.google_ads_manager_customer_id || '',
    });

    const fbForm = useForm({
        facebook_ads_account_id: customer.facebook_ads_account_id || '',
        facebook_page_url: '',
    });

    const msForm = useForm({
        microsoft_ads_customer_id: customer.microsoft_ads_customer_id || '',
        microsoft_ads_account_id: customer.microsoft_ads_account_id || '',
    });

    const saveFbAccountId = (e) => {
        e.preventDefault();
        fbForm.put(route('admin.customers.update-facebook', customer.id), {
            preserveScroll: true,
            onSuccess: () => setEditingFbAccount(false),
        });
    };

    const saveMsAccountIds = (e) => {
        e.preventDefault();
        msForm.put(route('admin.customers.update-microsoft', customer.id), {
            preserveScroll: true,
            onSuccess: () => setEditingMsAccount(false),
        });
    };

    const saveGoogleAccountIds = (e) => {
        e.preventDefault();
        googleForm.put(route('admin.customers.update-google', customer.id), {
            preserveScroll: true,
            onSuccess: () => setEditingGoogleAccount(false),
        });
    };

    const owner = customer.users?.[0];
    const campaigns = customer.campaigns || [];

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Admin - Customer Detail</h2>}
        >
            <Head title={`Admin - ${customer.business_name || 'Customer'}`} />

            <div className="flex">
                <SideNav />
                <div className="flex-1 py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                        {/* Back Button */}
                        <div>
                            <Link
                                href={route('admin.customers.index')}
                                className="inline-flex items-center text-sm text-gray-600 hover:text-gray-900"
                            >
                                <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                </svg>
                                Back to Customers
                            </Link>
                        </div>

                        {/* Customer Info Card */}
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <h3 className="text-2xl font-bold text-gray-900">{customer.business_name || 'Unnamed Business'}</h3>
                                        <p className="text-gray-500 mt-1">{customer.website_url}</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <Link
                                            href={route('admin.customers.dashboard', customer.id)}
                                            className="inline-flex items-center px-4 py-2 bg-flame-orange-600 text-white rounded-lg hover:bg-flame-orange-700"
                                        >
                                            <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                            </svg>
                                            View Performance
                                        </Link>
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                            Active
                                        </span>
                                    </div>
                                </div>
                                
                                <div className="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-500">Owner</h4>
                                        <p className="mt-1 text-gray-900">{owner?.name || 'N/A'}</p>
                                        <p className="text-gray-500">{owner?.email || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-500">Industry</h4>
                                        <p className="mt-1 text-gray-900">{customer.industry || 'Not specified'}</p>
                                    </div>
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-500">Created</h4>
                                        <p className="mt-1 text-gray-900">{new Date(customer.created_at).toLocaleDateString()}</p>
                                    </div>
                                </div>

                                {/* Connected Ad Accounts */}
                                <div className="mt-6 pt-6 border-t border-gray-200">
                                    <h4 className="text-sm font-medium text-gray-500 mb-3">Connected Ad Accounts</h4>

                                    {/* Google Ads Customer ID */}
                                    <div className="mb-4">
                                        <div className="flex items-center justify-between mb-1">
                                            <span className="text-xs font-medium text-gray-500">Google Ads Account</span>
                                            <button
                                                type="button"
                                                onClick={() => setEditingGoogleAccount(!editingGoogleAccount)}
                                                className="text-xs text-gray-500 hover:text-gray-700 font-medium transition"
                                            >
                                                {editingGoogleAccount ? 'Cancel' : 'Edit'}
                                            </button>
                                        </div>
                                        {editingGoogleAccount ? (
                                            <form onSubmit={saveGoogleAccountIds} className="space-y-3">
                                                <div>
                                                    <label className="block text-xs text-gray-500 mb-1">Customer ID (sub-account)</label>
                                                    <input
                                                        type="text"
                                                        value={googleForm.data.google_ads_customer_id}
                                                        onChange={e => googleForm.setData('google_ads_customer_id', e.target.value)}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500"
                                                        placeholder="e.g., 123-456-7890 or 1234567890"
                                                    />
                                                    {googleForm.errors.google_ads_customer_id && (
                                                        <p className="mt-1 text-xs text-red-600">{googleForm.errors.google_ads_customer_id}</p>
                                                    )}
                                                </div>
                                                <div>
                                                    <label className="block text-xs text-gray-500 mb-1">Manager Customer ID (MCC) — leave blank to use default</label>
                                                    <input
                                                        type="text"
                                                        value={googleForm.data.google_ads_manager_customer_id}
                                                        onChange={e => googleForm.setData('google_ads_manager_customer_id', e.target.value)}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500"
                                                        placeholder="e.g., 870-102-3448 (auto-fills from MCC config)"
                                                    />
                                                    {googleForm.errors.google_ads_manager_customer_id && (
                                                        <p className="mt-1 text-xs text-red-600">{googleForm.errors.google_ads_manager_customer_id}</p>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="submit"
                                                        disabled={googleForm.processing}
                                                        className="px-3 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50"
                                                    >
                                                        {googleForm.processing ? 'Saving...' : 'Save'}
                                                    </button>
                                                    <p className="text-xs text-gray-400">Create the sub-account in Google Ads UI, then paste the ID here.</p>
                                                </div>
                                            </form>
                                        ) : customer.google_ads_customer_id ? (
                                            <div className="space-y-1">
                                                <div className="px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-700 font-mono">
                                                    Customer: {customer.google_ads_customer_id}
                                                    {customer.google_ads_manager_customer_id && (
                                                        <span className="ml-2 text-gray-400">/ MCC: {customer.google_ads_manager_customer_id}</span>
                                                    )}
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="px-3 py-2 bg-amber-50 border border-amber-200 rounded-md text-sm text-amber-700">
                                                Not linked — Google Ads deployment is blocked until an account is assigned.
                                            </div>
                                        )}
                                    </div>

                                    {/* Facebook Ad Account */}
                                    <div>
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="text-xs font-medium text-gray-500">Facebook Ads Account</span>
                                        <div className="flex items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={() => setEditingFbAccount(!editingFbAccount)}
                                                className="text-xs text-gray-500 hover:text-gray-700 font-medium transition"
                                            >
                                                {editingFbAccount ? 'Cancel' : 'Edit ID'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setShowFacebookModal(true)}
                                                className="text-xs text-blue-600 hover:text-blue-800 font-medium transition"
                                            >
                                                {customer.facebook_ads_account_id ? 'Manage ↗' : '+ Link Account'}
                                            </button>
                                        </div>
                                    </div>
                                    {editingFbAccount ? (
                                        <form onSubmit={saveFbAccountId} className="space-y-3">
                                            <div className="flex items-center gap-2">
                                                <div className="flex-1">
                                                    <label className="block text-xs text-gray-500 mb-1">Ad Account ID</label>
                                                    <div className="flex items-center">
                                                        <span className="inline-flex items-center px-3 py-2 bg-gray-100 border border-r-0 border-gray-300 rounded-l-md text-sm text-gray-500">act_</span>
                                                        <input
                                                            type="text"
                                                            value={fbForm.data.facebook_ads_account_id}
                                                            onChange={e => fbForm.setData('facebook_ads_account_id', e.target.value)}
                                                            className="flex-1 px-3 py-2 border border-gray-300 rounded-r-md text-sm focus:ring-blue-500 focus:border-blue-500"
                                                            placeholder="123456789"
                                                        />
                                                    </div>
                                                    {fbForm.errors.facebook_ads_account_id && (
                                                        <p className="mt-1 text-xs text-red-600">{fbForm.errors.facebook_ads_account_id}</p>
                                                    )}
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-xs text-gray-500 mb-1">Facebook Page URL</label>
                                                <input
                                                    type="text"
                                                    value={fbForm.data.facebook_page_url}
                                                    onChange={e => fbForm.setData('facebook_page_url', e.target.value)}
                                                    className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500"
                                                    placeholder="https://www.facebook.com/YourPage"
                                                />
                                                {fbForm.errors.facebook_page_url && (
                                                    <p className="mt-1 text-xs text-red-600">{fbForm.errors.facebook_page_url}</p>
                                                )}
                                                <p className="mt-1 text-xs text-gray-400">Paste the full Facebook Page URL — we'll extract the Page ID automatically.</p>
                                            </div>
                                            <button
                                                type="submit"
                                                disabled={fbForm.processing}
                                                className="px-3 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50"
                                            >
                                                {fbForm.processing ? 'Saving...' : 'Save'}
                                            </button>
                                        </form>
                                    ) : customer.facebook_ads_account_id ? (
                                        <div className="px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-700 font-mono flex items-center gap-2">
                                            <span>act_{customer.facebook_ads_account_id}</span>
                                            {customer.facebook_bm_owned && (
                                                <span className="ml-auto text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-sans font-medium">BM Managed</span>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="px-3 py-2 bg-amber-50 border border-amber-200 rounded-md text-sm text-amber-700">
                                            Not linked — Facebook deployment is blocked until an account is assigned.
                                        </div>
                                    )}

                                    {/* Facebook Page */}
                                    <div className="mt-3">
                                        <span className="text-xs font-medium text-gray-500">Facebook Page</span>
                                        {customer.facebook_page_id ? (
                                            <div className="mt-1 px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-700 flex items-center gap-2">
                                                <svg className="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 24 24"><path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/></svg>
                                                <span>{customer.facebook_page_name || customer.facebook_page_id}</span>
                                                <a href={`https://facebook.com/${customer.facebook_page_id}`} target="_blank" rel="noopener" className="ml-auto text-xs text-blue-600 hover:text-blue-800">View ↗</a>
                                            </div>
                                        ) : (
                                            <div className="mt-1 px-3 py-2 bg-amber-50 border border-amber-200 rounded-md text-sm text-amber-700">
                                                No Facebook Page linked — click "Edit ID" above to add one.
                                            </div>
                                        )}
                                    </div>
                                    </div>

                                    {/* Microsoft Ads Account */}
                                    <div className="mt-4">
                                        <div className="flex items-center justify-between mb-1">
                                            <span className="text-xs font-medium text-gray-500">Microsoft Ads Account</span>
                                            <button
                                                type="button"
                                                onClick={() => setEditingMsAccount(!editingMsAccount)}
                                                className="text-xs text-gray-500 hover:text-gray-700 font-medium transition"
                                            >
                                                {editingMsAccount ? 'Cancel' : 'Edit'}
                                            </button>
                                        </div>
                                        {editingMsAccount ? (
                                            <form onSubmit={saveMsAccountIds} className="space-y-3">
                                                <div>
                                                    <label className="block text-xs text-gray-500 mb-1">Customer ID</label>
                                                    <input
                                                        type="text"
                                                        value={msForm.data.microsoft_ads_customer_id}
                                                        onChange={e => msForm.setData('microsoft_ads_customer_id', e.target.value)}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-teal-500 focus:border-teal-500"
                                                        placeholder="e.g., 123456789"
                                                    />
                                                    {msForm.errors.microsoft_ads_customer_id && (
                                                        <p className="mt-1 text-xs text-red-600">{msForm.errors.microsoft_ads_customer_id}</p>
                                                    )}
                                                </div>
                                                <div>
                                                    <label className="block text-xs text-gray-500 mb-1">Account ID</label>
                                                    <input
                                                        type="text"
                                                        value={msForm.data.microsoft_ads_account_id}
                                                        onChange={e => msForm.setData('microsoft_ads_account_id', e.target.value)}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-teal-500 focus:border-teal-500"
                                                        placeholder="e.g., 987654321"
                                                    />
                                                    {msForm.errors.microsoft_ads_account_id && (
                                                        <p className="mt-1 text-xs text-red-600">{msForm.errors.microsoft_ads_account_id}</p>
                                                    )}
                                                </div>
                                                <button
                                                    type="submit"
                                                    disabled={msForm.processing}
                                                    className="px-3 py-2 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700 disabled:opacity-50"
                                                >
                                                    {msForm.processing ? 'Saving...' : 'Save'}
                                                </button>
                                            </form>
                                        ) : customer.microsoft_ads_account_id ? (
                                            <div className="space-y-1">
                                                <div className="px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-700 font-mono">
                                                    Customer: {customer.microsoft_ads_customer_id} / Account: {customer.microsoft_ads_account_id}
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="px-3 py-2 bg-amber-50 border border-amber-200 rounded-md text-sm text-amber-700">
                                                Not linked — Microsoft Ads deployment is blocked until an account is assigned.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Campaigns Section */}
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6">
                                <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                    Campaigns ({campaigns.length})
                                </h3>
                                
                                {campaigns.length === 0 ? (
                                    <p className="text-gray-500 text-center py-8">No campaigns yet</p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full divide-y divide-gray-200">
                                            <thead className="bg-gray-50">
                                                <tr>
                                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Campaign</th>
                                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Budget</th>
                                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Strategies</th>
                                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Collateral</th>
                                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody className="bg-white divide-y divide-gray-200">
                                                {campaigns.map((campaign) => {
                                                    const strategies = campaign.strategies || [];
                                                    const totalAdCopies = strategies.reduce((sum, s) => sum + (s.ad_copies_count || 0), 0);
                                                    const totalImages = strategies.reduce((sum, s) => sum + (s.image_collaterals_count || 0), 0);
                                                    const totalVideos = strategies.reduce((sum, s) => sum + (s.video_collaterals_count || 0), 0);
                                                    
                                                    return (
                                                        <tr key={campaign.id}>
                                                            <td className="px-6 py-4 whitespace-nowrap">
                                                                <div className="text-sm font-medium text-gray-900">{campaign.name}</div>
                                                                <div className="text-sm text-gray-500">ID: {campaign.id}</div>
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap">
                                                                <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                                    campaign.platform_status === 'ENABLED' ? 'bg-green-100 text-green-800' :
                                                                    campaign.platform_status === 'PAUSED' ? 'bg-yellow-100 text-yellow-800' :
                                                                    'bg-gray-100 text-gray-800'
                                                                }`}>
                                                                    {campaign.platform_status || 'Draft'}
                                                                </span>
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                                ${campaign.daily_budget}/day
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap">
                                                                <span className="text-sm text-gray-900">{strategies.length} strategies</span>
                                                                <div className="text-xs text-gray-500">
                                                                    {strategies.filter(s => s.signed_off_at).length} signed off
                                                                </div>
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                                <div className="flex gap-2">
                                                                    {totalAdCopies > 0 && (
                                                                        <span className="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">{totalAdCopies} copies</span>
                                                                    )}
                                                                    {totalImages > 0 && (
                                                                        <span className="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">{totalImages} images</span>
                                                                    )}
                                                                    {totalVideos > 0 && (
                                                                        <span className="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs">{totalVideos} videos</span>
                                                                    )}
                                                                    {totalAdCopies === 0 && totalImages === 0 && totalVideos === 0 && (
                                                                        <span className="text-gray-400">None</span>
                                                                    )}
                                                                </div>
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                                <Link
                                                                    href={route('admin.campaigns.show', campaign.id)}
                                                                    className="text-flame-orange-600 hover:text-flame-orange-900 font-medium"
                                                                >
                                                                    View Details
                                                                </Link>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <FacebookAdAccountModal
                show={showFacebookModal}
                onClose={() => setShowFacebookModal(false)}
                customer={customer}
                bmConfigured={bm_configured}
            />
        </AuthenticatedLayout>
    );
}
