import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import axios from 'axios';
import SideNav from './SideNav';

// Performance Stats Component
const PerformanceStats = ({ stats, loading }) => {
    if (loading) {
        return (
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {[...Array(4)].map((_, i) => (
                    <div key={i} className="bg-white p-4 rounded-lg shadow animate-pulse">
                        <div className="h-4 bg-gray-200 rounded w-20 mb-2"></div>
                        <div className="h-8 bg-gray-200 rounded w-24"></div>
                    </div>
                ))}
            </div>
        );
    }

    const metrics = [
        { label: 'Impressions', value: stats?.impressions?.toLocaleString() || '0', color: 'text-blue-600' },
        { label: 'Clicks', value: stats?.clicks?.toLocaleString() || '0', color: 'text-green-600' },
        { label: 'Cost', value: `$${(stats?.cost || 0).toFixed(2)}`, color: 'text-red-600' },
        { label: 'Conversions', value: (stats?.conversions || 0).toFixed(1), color: 'text-purple-600' },
        { label: 'CTR', value: `${(stats?.ctr || 0).toFixed(2)}%`, color: 'text-indigo-600' },
        { label: 'CPC', value: `$${(stats?.cpc || 0).toFixed(2)}`, color: 'text-orange-600' },
        { label: 'CPA', value: stats?.cpa > 0 ? `$${stats.cpa.toFixed(2)}` : '-', color: 'text-pink-600' },
    ];

    return (
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
            {metrics.map((metric, i) => (
                <div key={i} className="bg-white p-4 rounded-lg shadow">
                    <p className="text-sm text-gray-500">{metric.label}</p>
                    <p className={`text-2xl font-bold ${metric.color}`}>{metric.value}</p>
                </div>
            ))}
        </div>
    );
};

export default function CustomerDashboard({ auth }) {
    const { customer, campaigns, defaultCampaign } = usePage().props;
    const [selectedCampaign, setSelectedCampaign] = useState(defaultCampaign);
    const [performanceData, setPerformanceData] = useState(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (selectedCampaign) {
            setLoading(true);
            axios.get(route('admin.campaigns.performance', { campaign: selectedCampaign.id }))
                .then(response => {
                    setPerformanceData(response.data);
                    setLoading(false);
                })
                .catch(error => {
                    console.error("Error fetching performance data:", error);
                    setLoading(false);
                });
        }
    }, [selectedCampaign]);

    const owner = customer.users?.[0];

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Admin - Customer Dashboard</h2>}
        >
            <Head title={`Dashboard - ${customer.business_name}`} />

            <div className="flex">
                <SideNav />
                <div className="flex-1 py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                        {/* Back Button */}
                        <div>
                            <Link
                                href={route('admin.customers.show', customer.id)}
                                className="inline-flex items-center text-sm text-gray-600 hover:text-gray-900"
                            >
                                <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                </svg>
                                Back to Customer Details
                            </Link>
                        </div>

                        {/* Customer Header */}
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                            <div className="flex justify-between items-start">
                                <div>
                                    <h3 className="text-2xl font-bold text-gray-900">{customer.business_name || 'Unnamed Business'}</h3>
                                    <p className="text-gray-500 mt-1">{owner?.email} • {customer.website_url}</p>
                                </div>
                                <Link
                                    href={route('admin.customers.show', customer.id)}
                                    className="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
                                >
                                    View All Details
                                </Link>
                            </div>
                        </div>

                        {/* Campaign Selector */}
                        {campaigns.length > 0 ? (
                            <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h4 className="text-lg font-semibold text-gray-900">Campaign Performance</h4>
                                    <select
                                        value={selectedCampaign?.id || ''}
                                        onChange={(e) => {
                                            const campaign = campaigns.find(c => c.id === parseInt(e.target.value));
                                            setSelectedCampaign(campaign);
                                        }}
                                        className="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        {campaigns.map((campaign) => (
                                            <option key={campaign.id} value={campaign.id}>
                                                {campaign.name} {campaign.platform_status ? `(${campaign.platform_status})` : ''}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                {/* Performance Stats */}
                                <PerformanceStats stats={performanceData?.summary} loading={loading} />

                                {/* Message if no data */}
                                {performanceData?.message && (
                                    <div className="mt-4 p-4 bg-yellow-50 text-yellow-800 rounded-lg text-sm">
                                        {performanceData.message}
                                    </div>
                                )}

                                {performanceData?.error && (
                                    <div className="mt-4 p-4 bg-red-50 text-red-800 rounded-lg text-sm">
                                        Error: {performanceData.error}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 text-center">
                                <p className="text-gray-500">No campaigns found for this customer.</p>
                            </div>
                        )}

                        {/* All Campaigns Summary */}
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6">
                                <h4 className="text-lg font-semibold text-gray-900 mb-4">All Campaigns ({campaigns.length})</h4>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Campaign</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Budget</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Google Ads ID</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {campaigns.map((campaign) => (
                                                <tr 
                                                    key={campaign.id} 
                                                    className={`hover:bg-gray-50 cursor-pointer ${selectedCampaign?.id === campaign.id ? 'bg-indigo-50' : ''}`}
                                                    onClick={() => setSelectedCampaign(campaign)}
                                                >
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm font-medium text-gray-900">{campaign.name}</div>
                                                        <div className="text-xs text-gray-500">Created {new Date(campaign.created_at).toLocaleDateString()}</div>
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
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {campaign.google_ads_campaign_id || '-'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        <Link
                                                            href={route('admin.campaigns.show', campaign.id)}
                                                            className="text-indigo-600 hover:text-indigo-900"
                                                            onClick={(e) => e.stopPropagation()}
                                                        >
                                                            Details
                                                        </Link>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
