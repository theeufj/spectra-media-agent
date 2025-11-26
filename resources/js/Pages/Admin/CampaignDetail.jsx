import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage, useForm } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import axios from 'axios';
import SideNav from './SideNav';

// Performance Stats Component
const PerformanceStats = ({ stats, loading }) => {
    if (loading) {
        return (
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {[...Array(4)].map((_, i) => (
                    <div key={i} className="p-4 rounded-lg bg-gray-100 animate-pulse">
                        <div className="h-4 bg-gray-200 rounded w-20 mb-2"></div>
                        <div className="h-8 bg-gray-200 rounded w-24"></div>
                    </div>
                ))}
            </div>
        );
    }

    if (!stats) return null;

    const metrics = [
        { label: 'Impressions', value: stats.impressions?.toLocaleString() || '0', color: 'text-blue-600', bg: 'bg-blue-50' },
        { label: 'Clicks', value: stats.clicks?.toLocaleString() || '0', color: 'text-green-600', bg: 'bg-green-50' },
        { label: 'Cost', value: `$${(stats.cost || 0).toFixed(2)}`, color: 'text-red-600', bg: 'bg-red-50' },
        { label: 'Conversions', value: (stats.conversions || 0).toFixed(1), color: 'text-purple-600', bg: 'bg-purple-50' },
        { label: 'CTR', value: `${(stats.ctr || 0).toFixed(2)}%`, color: 'text-indigo-600', bg: 'bg-indigo-50' },
        { label: 'CPC', value: `$${(stats.cpc || 0).toFixed(2)}`, color: 'text-orange-600', bg: 'bg-orange-50' },
        { label: 'CPA', value: stats.cpa > 0 ? `$${stats.cpa.toFixed(2)}` : '-', color: 'text-pink-600', bg: 'bg-pink-50' },
    ];

    return (
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
            {metrics.map((metric, i) => (
                <div key={i} className={`p-4 rounded-lg ${metric.bg}`}>
                    <p className="text-xs text-gray-500 uppercase tracking-wide">{metric.label}</p>
                    <p className={`text-xl font-bold ${metric.color}`}>{metric.value}</p>
                </div>
            ))}
        </div>
    );
};

export default function CampaignDetail({ auth }) {
    const { campaign, flash } = usePage().props;
    const [isEditing, setIsEditing] = useState(false);
    const [expandedStrategy, setExpandedStrategy] = useState(null);
    const [performanceData, setPerformanceData] = useState(null);
    const [performanceLoading, setPerformanceLoading] = useState(true);

    const { data, setData, put, processing } = useForm({
        name: campaign.name,
        daily_budget: campaign.daily_budget,
        total_budget: campaign.total_budget,
    });

    const { post: pausePost, processing: pauseProcessing } = useForm();
    const { post: startPost, processing: startProcessing } = useForm();

    const customer = campaign.customer;
    const owner = customer?.users?.[0];
    const strategies = campaign.strategies || [];

    // Fetch performance data
    useEffect(() => {
        if (campaign.google_ads_campaign_id) {
            setPerformanceLoading(true);
            axios.get(route('admin.campaigns.performance', { campaign: campaign.id }))
                .then(response => {
                    // API returns { summary: {...}, daily_data: [...] }
                    setPerformanceData(response.data.summary || response.data);
                    setPerformanceLoading(false);
                })
                .catch(error => {
                    console.error("Error fetching performance data:", error);
                    setPerformanceLoading(false);
                });
        } else {
            setPerformanceLoading(false);
        }
    }, [campaign.id]);

    const handleUpdate = (e) => {
        e.preventDefault();
        put(route('admin.campaigns.update', campaign.id), {
            onSuccess: () => setIsEditing(false),
        });
    };

    const handlePause = () => {
        if (confirm('Are you sure you want to pause this campaign?')) {
            pausePost(route('admin.campaigns.pause', campaign.id));
        }
    };

    const handleStart = () => {
        if (confirm('Are you sure you want to start this campaign?')) {
            startPost(route('admin.campaigns.start', campaign.id));
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Admin - Campaign Detail</h2>}
        >
            <Head title={`Admin - ${campaign.name}`} />

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
                                Back to {customer.business_name || 'Customer'}
                            </Link>
                        </div>

                        {/* Flash Messages */}
                        {flash?.message && (
                            <div className={`p-4 rounded-lg ${flash.type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                                {flash.message}
                            </div>
                        )}

                        {/* Campaign Info Card */}
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <h3 className="text-2xl font-bold text-gray-900">{campaign.name}</h3>
                                        <p className="text-gray-500 mt-1">
                                            {customer.business_name} • {owner?.email}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                                            campaign.platform_status === 'ENABLED' ? 'bg-green-100 text-green-800' :
                                            campaign.platform_status === 'PAUSED' ? 'bg-yellow-100 text-yellow-800' :
                                            'bg-gray-100 text-gray-800'
                                        }`}>
                                            {campaign.platform_status || 'Draft'}
                                        </span>
                                        
                                        {campaign.google_ads_campaign_id && (
                                            <>
                                                {campaign.platform_status === 'ENABLED' ? (
                                                    <button
                                                        onClick={handlePause}
                                                        disabled={pauseProcessing}
                                                        className="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 disabled:opacity-50"
                                                    >
                                                        {pauseProcessing ? 'Pausing...' : 'Pause Campaign'}
                                                    </button>
                                                ) : campaign.platform_status === 'PAUSED' ? (
                                                    <button
                                                        onClick={handleStart}
                                                        disabled={startProcessing}
                                                        className="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50"
                                                    >
                                                        {startProcessing ? 'Starting...' : 'Start Campaign'}
                                                    </button>
                                                ) : null}
                                            </>
                                        )}
                                    </div>
                                </div>

                                {/* Campaign Details */}
                                {isEditing ? (
                                    <form onSubmit={handleUpdate} className="mt-6 space-y-4">
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Name</label>
                                                <input
                                                    type="text"
                                                    value={data.name}
                                                    onChange={(e) => setData('name', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Daily Budget ($)</label>
                                                <input
                                                    type="number"
                                                    value={data.daily_budget}
                                                    onChange={(e) => setData('daily_budget', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Total Budget ($)</label>
                                                <input
                                                    type="number"
                                                    value={data.total_budget}
                                                    onChange={(e) => setData('total_budget', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                />
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                type="submit"
                                                disabled={processing}
                                                className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                                            >
                                                {processing ? 'Saving...' : 'Save Changes'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setIsEditing(false)}
                                                className="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="mt-6 grid grid-cols-2 md:grid-cols-4 gap-6">
                                        <div>
                                            <h4 className="text-sm font-medium text-gray-500">Daily Budget</h4>
                                            <p className="mt-1 text-xl font-semibold text-gray-900">${campaign.daily_budget}</p>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-medium text-gray-500">Total Budget</h4>
                                            <p className="mt-1 text-xl font-semibold text-gray-900">${campaign.total_budget}</p>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-medium text-gray-500">Start Date</h4>
                                            <p className="mt-1 text-gray-900">{campaign.start_date ? new Date(campaign.start_date).toLocaleDateString() : 'Not set'}</p>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-medium text-gray-500">End Date</h4>
                                            <p className="mt-1 text-gray-900">{campaign.end_date ? new Date(campaign.end_date).toLocaleDateString() : 'Not set'}</p>
                                        </div>
                                    </div>
                                )}
                                
                                {!isEditing && (
                                    <button
                                        onClick={() => setIsEditing(true)}
                                        className="mt-4 text-sm text-indigo-600 hover:text-indigo-900"
                                    >
                                        Edit Campaign Settings
                                    </button>
                                )}

                                {/* Google Ads Info */}
                                {campaign.google_ads_campaign_id && (
                                    <div className="mt-6 p-4 bg-gray-50 rounded-lg">
                                        <h4 className="text-sm font-medium text-gray-700">Google Ads</h4>
                                        <p className="text-sm text-gray-500 mt-1">Campaign ID: {campaign.google_ads_campaign_id}</p>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Performance Section */}
                        {campaign.google_ads_campaign_id && (
                            <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                                <div className="p-6">
                                    <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                        Performance (Last 30 Days)
                                    </h3>
                                    <PerformanceStats stats={performanceData} loading={performanceLoading} />
                                    {!performanceLoading && !performanceData && (
                                        <p className="text-gray-500 text-center py-4">No performance data available</p>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Strategies Section */}
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6">
                                <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                    Strategies ({strategies.length})
                                </h3>
                                
                                {strategies.length === 0 ? (
                                    <p className="text-gray-500 text-center py-8">No strategies generated yet</p>
                                ) : (
                                    <div className="space-y-4">
                                        {strategies.map((strategy) => (
                                            <div key={strategy.id} className="border border-gray-200 rounded-lg">
                                                <button
                                                    onClick={() => setExpandedStrategy(expandedStrategy === strategy.id ? null : strategy.id)}
                                                    className="w-full px-6 py-4 flex items-center justify-between hover:bg-gray-50"
                                                >
                                                    <div className="flex items-center gap-4">
                                                        <span className="font-semibold text-gray-900">{strategy.platform}</span>
                                                        <span className={`px-2 py-0.5 rounded text-xs ${strategy.signed_off_at ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'}`}>
                                                            {strategy.signed_off_at ? 'Signed Off' : 'Pending'}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center gap-4">
                                                        <div className="flex gap-2 text-sm">
                                                            <span className="px-2 py-0.5 bg-blue-100 text-blue-700 rounded">{strategy.ad_copies_count || 0} copies</span>
                                                            <span className="px-2 py-0.5 bg-green-100 text-green-700 rounded">{strategy.image_collaterals_count || 0} images</span>
                                                            <span className="px-2 py-0.5 bg-purple-100 text-purple-700 rounded">{strategy.video_collaterals_count || 0} videos</span>
                                                        </div>
                                                        <svg className={`w-5 h-5 text-gray-400 transition-transform ${expandedStrategy === strategy.id ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                                        </svg>
                                                    </div>
                                                </button>
                                                
                                                {expandedStrategy === strategy.id && (
                                                    <div className="px-6 py-4 border-t border-gray-200 bg-gray-50">
                                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                                            <div>
                                                                <h5 className="text-sm font-medium text-gray-700 mb-2">Ad Copy Strategy</h5>
                                                                <p className="text-sm text-gray-600 whitespace-pre-wrap">{strategy.ad_copy_strategy || 'N/A'}</p>
                                                            </div>
                                                            <div>
                                                                <h5 className="text-sm font-medium text-gray-700 mb-2">Imagery Strategy</h5>
                                                                <p className="text-sm text-gray-600 whitespace-pre-wrap">{strategy.imagery_strategy || 'N/A'}</p>
                                                            </div>
                                                            <div>
                                                                <h5 className="text-sm font-medium text-gray-700 mb-2">Video Strategy</h5>
                                                                <p className="text-sm text-gray-600 whitespace-pre-wrap">{strategy.video_strategy || 'N/A'}</p>
                                                            </div>
                                                        </div>

                                                        {/* Collateral Preview */}
                                                        {(strategy.ad_copies?.length > 0 || strategy.image_collaterals?.length > 0) && (
                                                            <div className="mt-6 pt-4 border-t border-gray-200">
                                                                <h5 className="text-sm font-medium text-gray-700 mb-3">Collateral Preview</h5>
                                                                
                                                                {/* Ad Copies */}
                                                                {strategy.ad_copies?.length > 0 && (
                                                                    <div className="mb-4">
                                                                        <h6 className="text-xs font-medium text-gray-500 uppercase mb-2">Ad Copies</h6>
                                                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                                                                            {strategy.ad_copies.slice(0, 4).map((copy) => (
                                                                                <div key={copy.id} className="p-3 bg-white rounded border text-sm">
                                                                                    <p className="font-medium text-gray-900">{copy.headline}</p>
                                                                                    <p className="text-gray-600 mt-1">{copy.description}</p>
                                                                                </div>
                                                                            ))}
                                                                        </div>
                                                                    </div>
                                                                )}
                                                                
                                                                {/* Images */}
                                                                {strategy.image_collaterals?.length > 0 && (
                                                                    <div>
                                                                        <h6 className="text-xs font-medium text-gray-500 uppercase mb-2">Images</h6>
                                                                        <div className="flex gap-2 overflow-x-auto">
                                                                            {strategy.image_collaterals.slice(0, 6).map((image) => (
                                                                                <img
                                                                                    key={image.id}
                                                                                    src={image.image_url}
                                                                                    alt="Ad creative"
                                                                                    className="w-32 h-32 object-cover rounded border"
                                                                                />
                                                                            ))}
                                                                        </div>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Raw Campaign Data (for debugging) */}
                        <details className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <summary className="p-6 cursor-pointer text-gray-500 hover:text-gray-700">
                                Raw Campaign Data (Debug)
                            </summary>
                            <pre className="p-6 pt-0 text-xs text-gray-600 overflow-auto max-h-96">
                                {JSON.stringify(campaign, null, 2)}
                            </pre>
                        </details>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
