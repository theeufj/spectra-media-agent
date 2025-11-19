import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import ConfirmationModal from '@/Components/ConfirmationModal';
import React from 'react';

export default function Index({ auth, campaigns = [] }) {
    const [expandedCampaign, setExpandedCampaign] = React.useState(null);
    const [confirmModal, setConfirmModal] = React.useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });

    const handleDelete = (campaignId) => {
        setConfirmModal({
            show: true,
            title: 'Delete Campaign',
            message: 'Are you sure you want to delete this campaign? This action cannot be undone.',
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                router.delete(route('campaigns.destroy', campaignId));
            },
            confirmText: 'Delete',
            confirmButtonClass: 'bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800',
            isDestructive: true
        });
    };

    const getCollateralSummary = (strategy) => {
        return {
            adCopies: strategy.ad_copies_count || 0,
            images: strategy.image_collaterals_count || 0,
            videos: strategy.video_collaterals_count || 0,
            total: (strategy.ad_copies_count || 0) + (strategy.image_collaterals_count || 0) + (strategy.video_collaterals_count || 0)
        };
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Campaigns</h2>}
        >
            <Head title="Campaigns" />

            <ConfirmationModal
                show={confirmModal.show}
                onClose={() => setConfirmModal({ ...confirmModal, show: false })}
                onConfirm={confirmModal.onConfirm}
                title={confirmModal.title}
                message={confirmModal.message}
                confirmText={confirmModal.confirmText}
                confirmButtonClass={confirmModal.confirmButtonClass}
                isDestructive={confirmModal.isDestructive}
            />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {campaigns && campaigns.length > 0 ? (
                        campaigns.map(campaign => {
                            const isExpanded = expandedCampaign === campaign.id;
                            return (
                                <div key={campaign.id} className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-4">
                                    {/* Campaign Header - Collapsible */}
                                    <div 
                                        onClick={() => setExpandedCampaign(isExpanded ? null : campaign.id)}
                                        className="p-6 text-gray-900 cursor-pointer hover:bg-gray-50 transition-colors flex justify-between items-center"
                                    >
                                        <div className="flex-1">
                                            <div className="flex items-center gap-4">
                                                <svg className={`h-6 w-6 text-gray-400 transition-transform ${isExpanded ? 'rotate-90' : ''}`} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                                </svg>
                                                <div>
                                                    <h3 className="text-2xl font-bold">{campaign.name}</h3>
                                                    <p className="text-sm text-gray-500 mt-1">{campaign.reason}</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className={`px-3 py-1 rounded-full text-xs font-semibold ${
                                                campaign.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'
                                            }`}>
                                                {campaign.status}
                                            </span>
                                            <span className="text-sm font-semibold text-indigo-600 bg-indigo-50 px-3 py-1 rounded">{campaign.strategies?.length || 0} strategies</span>
                                        </div>
                                    </div>

                                    {/* Expanded Content */}
                                    {isExpanded && (
                                        <div className="border-t border-gray-200 p-6">
                                            {/* Campaign Details */}
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 pb-6 border-b border-gray-200">
                                                <div>
                                                    <p className="text-xs text-gray-500 uppercase">Budget</p>
                                                    <p className="font-semibold">${parseFloat(campaign.total_budget || 0).toFixed(2)}</p>
                                                </div>
                                                <div>
                                                    <p className="text-xs text-gray-500 uppercase">Start Date</p>
                                                    <p className="font-semibold">{campaign.start_date}</p>
                                                </div>
                                                <div>
                                                    <p className="text-xs text-gray-500 uppercase">End Date</p>
                                                    <p className="font-semibold">{campaign.end_date}</p>
                                                </div>
                                                <div>
                                                    <p className="text-xs text-gray-500 uppercase">Primary KPI</p>
                                                    <p className="font-semibold text-sm">{campaign.primary_kpi}</p>
                                                </div>
                                            </div>

                                            {/* Strategies Section */}
                                            <div>
                                                <h4 className="text-lg font-semibold mb-4">Strategies & Collateral</h4>
                                                <div className="space-y-4">
                                                    {campaign.strategies && campaign.strategies.length > 0 ? (
                                                        campaign.strategies.map(strategy => {
                                                            const summary = getCollateralSummary(strategy);
                                                            return (
                                                                <div key={strategy.id} className="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                                                    {/* Strategy Header */}
                                                                    <div className="flex justify-between items-start mb-3">
                                                                        <div className="flex-1">
                                                                            <h5 className="font-semibold text-gray-900">{strategy.platform}</h5>
                                                                            <p className="text-xs text-gray-500 mt-1 line-clamp-2">{strategy.ad_copy_strategy}</p>
                                                                        </div>
                                                                        <span className={`ml-4 px-2 py-1 rounded text-xs font-semibold whitespace-nowrap ${
                                                                            strategy.status === 'pending_approval' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'
                                                                        }`}>
                                                                            {strategy.status === 'pending_approval' ? 'Pending' : 'Approved'}
                                                                        </span>
                                                                    </div>

                                                                    {/* Collateral Summary */}
                                                                    <div className="bg-gray-50 rounded p-3 mb-3">
                                                                        <div className="flex items-center justify-between">
                                                                            <div className="flex gap-6">
                                                                                <div className="flex flex-col items-center">
                                                                                    <span className="text-2xl font-bold text-indigo-600">{summary.adCopies}</span>
                                                                                    <span className="text-xs text-gray-600">Ad Copies</span>
                                                                                </div>
                                                                                <div className="flex flex-col items-center">
                                                                                    <span className="text-2xl font-bold text-indigo-600">{summary.images}</span>
                                                                                    <span className="text-xs text-gray-600">Images</span>
                                                                                </div>
                                                                                <div className="flex flex-col items-center">
                                                                                    <span className="text-2xl font-bold text-indigo-600">{summary.videos}</span>
                                                                                    <span className="text-xs text-gray-600">Videos</span>
                                                                                </div>
                                                                            </div>
                                                                            <Link 
                                                                                href={route('campaigns.collateral.show', { campaign: campaign.id, strategy: strategy.id })} 
                                                                                className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-medium"
                                                                            >
                                                                                View Details
                                                                            </Link>
                                                                        </div>
                                                                    </div>

                                                                    {/* Bidding Strategy */}
                                                                    <div className="flex justify-between items-center text-xs text-gray-600">
                                                                        <span><strong>Bidding:</strong> {strategy.bidding_strategy?.name}</span>
                                                                        <span><strong>CPA Multiple:</strong> {strategy.revenue_cpa_multiple}x</span>
                                                                    </div>
                                                                </div>
                                                            );
                                                        })
                                                    ) : (
                                                        <div className="text-center py-8">
                                                            <p className="text-gray-500">No strategies created yet.</p>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Delete Button */}
                                            <div className="mt-6 pt-6 border-t border-gray-200">
                                                <button 
                                                    onClick={() => handleDelete(campaign.id)} 
                                                    className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-medium"
                                                >
                                                    Delete Campaign
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            );
                        })
                    ) : (
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6 text-center text-gray-900">
                                <h3 className="text-lg font-bold">No campaigns yet!</h3>
                                <p className="mt-2">Get started by creating your first campaign.</p>
                                <Link
                                    href="/campaigns/create"
                                    className="mt-4 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 active:bg-gray-900 focus:outline-none focus:border-gray-900 focus:ring ring-gray-300 disabled:opacity-25 transition ease-in-out duration-150"
                                >
                                    Create Your First Campaign
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}