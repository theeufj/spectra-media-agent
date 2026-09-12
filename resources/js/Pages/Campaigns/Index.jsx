import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { brandTint } from '@/Components/Marketing/Hero';
import { MegaphoneIcon } from '@heroicons/react/24/outline';
import ConfirmationModal from '@/Components/ConfirmationModal';
import React from 'react';
import { money, date } from '@/utils/format';
import { useCurrency } from '@/hooks/useCurrency';

/**
 * Has this strategy been sent to a platform?
 *
 * `deployment_status` in production holds null, 'active', 'deployed',
 * 'verified' and 'deploy_unverified'. Anything other than null means a
 * deployment was attempted and there is something to look at.
 */
const hasDeployed = (strategy) =>
    Boolean(strategy?.deployed_at) || Boolean(strategy?.deployment_status);

// Mirrors App\Enums\CampaignStatus. Values are canonical lowercase — the column
// previously held mixed casing ('DRAFT', 'PAUSED') and this badge only ever
// special-cased 'DRAFT', so paused campaigns rendered as green/active.
const CAMPAIGN_STATUS_LABELS = {
    draft: 'Draft',
    pending_admin_deployment: 'Pending deployment',
    active: 'Active',
    paused: 'Paused',
    completed: 'Completed',
    ended: 'Ended',
};

const CAMPAIGN_STATUS_STYLES = {
    draft: 'bg-yellow-100 text-yellow-800',
    pending_admin_deployment: 'bg-yellow-100 text-yellow-800',
    active: 'bg-green-100 text-green-800',
    paused: 'bg-gray-200 text-gray-800',
    completed: 'bg-blue-100 text-blue-800',
    ended: 'bg-gray-100 text-gray-600',
};

export default function Index({ auth, campaigns = [] }) {
    const currency = useCurrency();
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

            <div className="py-6 sm:py-12">
                <div className="max-w-7xl mx-auto">
                    {campaigns && campaigns.length > 0 ? (
                        campaigns.map(campaign => {
                            const isExpanded = expandedCampaign === campaign.id;
                            return (
                                <div key={campaign.id} className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-4">
                                    {/* Campaign Header - Collapsible */}
                                    <div 
                                        onClick={() => setExpandedCampaign(isExpanded ? null : campaign.id)}
                                        className="p-4 sm:p-6 text-gray-900 cursor-pointer hover:bg-gray-50 transition-colors flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3"
                                    >
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-3 sm:gap-4">
                                                <svg className={`h-5 w-5 sm:h-6 sm:w-6 text-gray-500 transition-transform flex-shrink-0 ${isExpanded ? 'rotate-90' : ''}`} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                                </svg>
                                                <div className="min-w-0">
                                                    <h3 className="text-lg sm:text-2xl font-bold truncate">{campaign.name}</h3>
                                                    <p className="text-sm text-gray-500 mt-1 truncate">{campaign.reason}</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 ml-8 sm:ml-0">
                                            <span className={`px-3 py-1 rounded-full text-xs font-semibold ${
                                                CAMPAIGN_STATUS_STYLES[campaign.status] ?? 'bg-gray-100 text-gray-800'
                                            }`}>
                                                {CAMPAIGN_STATUS_LABELS[campaign.status] ?? campaign.status}
                                            </span>
                                            <span className="text-sm font-semibold text-brand-dark px-3 py-1 rounded" style={{ backgroundColor: brandTint(10) }}>{campaign.strategies?.length || 0} {(campaign.strategies?.length || 0) === 1 ? 'strategy' : 'strategies'}</span>
                                        </div>
                                    </div>

                                    {/* Expanded Content */}
                                    {isExpanded && (
                                        <div className="border-t border-gray-200 p-6">
                                            {/* Campaign Details */}
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 pb-6 border-b border-gray-200">
                                                <div>
                                                    <p className="text-xs text-gray-500 uppercase">Budget</p>
                                                    <p className="font-semibold">{money(campaign.total_budget || 0, currency)}</p>
                                                </div>
                                                <div>
                                                    <p className="text-xs text-gray-500 uppercase">Start Date</p>
                                                    <p className="font-semibold">{campaign.start_date ? date(campaign.start_date) : '—'}</p>
                                                </div>
                                                <div>
                                                    <p className="text-xs text-gray-500 uppercase">End Date</p>
                                                    <p className="font-semibold">{campaign.end_date ? date(campaign.end_date) : '—'}</p>
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
                                                                                    <span className="text-2xl font-bold text-brand-dark">{summary.adCopies}</span>
                                                                                    <span className="text-xs text-gray-600">Ad Copies</span>
                                                                                </div>
                                                                                <div className="flex flex-col items-center">
                                                                                    <span className="text-2xl font-bold text-brand-dark">{summary.images}</span>
                                                                                    <span className="text-xs text-gray-600">Images</span>
                                                                                </div>
                                                                                <div className="flex flex-col items-center">
                                                                                    <span className="text-2xl font-bold text-brand-dark">{summary.videos}</span>
                                                                                    <span className="text-xs text-gray-600">Videos</span>
                                                                                </div>
                                                                            </div>
                                                                            {/*
                                                                                Deploy lives on the collateral page and this was the
                                                                                only route to it, labelled "View Details" — a name
                                                                                that promises reading, not doing. The step the whole
                                                                                funnel exists to reach was behind a link that sounded
                                                                                like a detour, so the label now says what is actually
                                                                                on the other side of it.
                                                                            */}
                                                                            <div className="flex items-center gap-2">
                                                                                {hasDeployed(strategy) && (
                                                                                    <Link
                                                                                        href={route('campaigns.deployment-status', { campaign: campaign.id })}
                                                                                        className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium text-gray-700"
                                                                                    >
                                                                                        Deployment status
                                                                                    </Link>
                                                                                )}
                                                                                <Link
                                                                                    href={route('campaigns.collateral.show', { campaign: campaign.id, strategy: strategy.id })}
                                                                                    className="px-4 py-2 bg-brand-dark text-white rounded-lg hover:bg-brand-darker text-sm font-medium"
                                                                                >
                                                                                    {hasDeployed(strategy) ? 'Review creative' : 'Review & deploy'}
                                                                                </Link>
                                                                            </div>
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
                        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                            <div className="p-8 text-center sm:p-12">
                                <span
                                    className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full text-brand-darker"
                                    style={{ backgroundColor: brandTint(12) }}
                                >
                                    <MegaphoneIcon className="h-6 w-6" aria-hidden="true" />
                                </span>
                                <h3 className="text-lg font-bold text-gray-900">No campaigns yet</h3>
                                <p className="mx-auto mt-2 max-w-sm text-sm text-gray-600">
                                    Your first campaign takes a couple of minutes. We write the ads; you approve
                                    them before anything goes live.
                                </p>
                                {/*
                                    The one primary action in the product still
                                    wearing the stock Breeze `bg-gray-800`. Every
                                    other one goes through PrimaryButton, so this
                                    empty state offered a dark grey button on a
                                    page whose header CTA is brand orange.
                                */}
                                <Link
                                    href="/campaigns/wizard"
                                    className="mt-6 inline-flex items-center rounded-md border border-transparent bg-brand-dark px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-brand-darker focus:outline-none focus:ring-2 focus:ring-brand-dark focus:ring-offset-2"
                                >
                                    Create your first campaign
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}