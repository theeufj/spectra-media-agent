import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { LightBulbIcon } from '@heroicons/react/24/outline';

const decodePaginationLabel = (html) =>
    html
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&hellip;/g, '…')
        .replace(/&amp;/g, '&')
        .replace(/<[^>]+>/g, '');

const PLATFORM_LABELS = {
    google: 'Google',
    facebook: 'Facebook',
    microsoft: 'Microsoft',
    linkedin: 'LinkedIn',
    unknown: '—',
};

const BRIEF_TYPE_LABELS = {
    fatigue_refresh: 'Creative Fatigue',
    ab_winner: 'A/B Winner',
    scheduled_refresh: 'Scheduled Refresh',
    cross_platform_winner: 'Cross-Platform Winner',
};

const STATUS_COLORS = {
    pending:   'bg-yellow-100 text-yellow-800',
    in_review: 'bg-blue-100 text-blue-800',
    actioned:  'bg-green-100 text-green-800',
    dismissed: 'bg-gray-100 text-gray-600',
};

function BriefCard({ brief, onAction, onDismiss }) {
    const [expanded, setExpanded] = useState(false);
    const context = brief.context ?? {};

    return (
        <div className="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
            <div className="p-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex-1 min-w-0">
                        <div className="flex flex-wrap items-center gap-2 mb-1">
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${STATUS_COLORS[brief.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                {brief.status}
                            </span>
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">
                                {BRIEF_TYPE_LABELS[brief.brief_type] ?? brief.brief_type}
                            </span>
                            <span className="text-xs text-gray-500">
                                {PLATFORM_LABELS[brief.platform] ?? brief.platform}
                            </span>
                        </div>
                        <p className="text-sm font-semibold text-gray-900 truncate">
                            {brief.campaign?.name ?? `Campaign #${brief.campaign_id}`}
                        </p>
                    </div>
                    <span className="text-xs text-gray-500 shrink-0">
                        {new Date(brief.created_at).toLocaleDateString()}
                    </span>
                </div>

                <p className="mt-3 text-sm text-gray-700 leading-relaxed">{brief.ai_brief}</p>

                {/* Expandable context */}
                {Object.keys(context).length > 0 && (
                    <div className="mt-3">
                        <button
                            onClick={() => setExpanded(e => !e)}
                            className="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                        >
                            {expanded ? 'Hide details' : 'Show details'}
                        </button>
                        {expanded && (
                            <div className="mt-2 bg-gray-50 rounded p-3 text-xs text-gray-700 space-y-2">
                                {context.winning_headlines?.length > 0 && (
                                    <div>
                                        <p className="font-semibold mb-1">Winning headlines:</p>
                                        <ul className="list-disc list-inside space-y-0.5">
                                            {context.winning_headlines.map((h, i) => <li key={i}>{h}</li>)}
                                        </ul>
                                    </div>
                                )}
                                {context.facebook_variants?.headlines?.length > 0 && (
                                    <div>
                                        <p className="font-semibold mb-1">Facebook headline variants:</p>
                                        <ul className="list-disc list-inside space-y-0.5">
                                            {context.facebook_variants.headlines.map((h, i) => <li key={i}>{h}</li>)}
                                        </ul>
                                    </div>
                                )}
                                {context.facebook_variants?.primary_texts?.length > 0 && (
                                    <div>
                                        <p className="font-semibold mb-1">Facebook primary text variants:</p>
                                        <ul className="list-disc list-inside space-y-0.5">
                                            {context.facebook_variants.primary_texts.map((t, i) => <li key={i}>{t}</li>)}
                                        </ul>
                                    </div>
                                )}
                                {context.generated_variants && (
                                    <div>
                                        <p className="font-semibold mb-1">Generated variants:</p>
                                        <pre className="whitespace-pre-wrap text-gray-600">{JSON.stringify(context.generated_variants, null, 2)}</pre>
                                    </div>
                                )}
                                {context.reason && <p><span className="font-semibold">Reason:</span> {context.reason}</p>}
                                {context.campaign_age_days && <p><span className="font-semibold">Campaign age:</span> {context.campaign_age_days} days</p>}
                            </div>
                        )}
                    </div>
                )}

                {brief.status === 'pending' && (
                    <div className="mt-4 flex gap-2">
                        <button
                            onClick={() => onAction(brief.id)}
                            className="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded hover:bg-green-700 transition"
                        >
                            Mark Actioned
                        </button>
                        <button
                            onClick={() => onDismiss(brief.id)}
                            className="px-3 py-1.5 text-xs font-medium text-gray-600 border border-gray-300 rounded hover:bg-gray-50 transition"
                        >
                            Dismiss
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function CreativeBriefsIndex({ briefs, counts, activeStatus }) {
    const { flash } = usePage().props;

    const tabs = [
        { key: 'pending',   label: 'Pending',   count: counts.pending   ?? 0 },
        { key: 'in_review', label: 'In Review',  count: counts.in_review ?? 0 },
        { key: 'actioned',  label: 'Actioned',   count: counts.actioned  ?? 0 },
        { key: 'dismissed', label: 'Dismissed',  count: counts.dismissed ?? 0 },
        { key: 'all',       label: 'All',        count: null },
    ];

    function switchTab(status) {
        router.get(route('creative-briefs.index'), { status }, { preserveScroll: true });
    }

    function handleAction(id) {
        router.post(route('creative-briefs.action', id), {}, { preserveScroll: true });
    }

    function handleDismiss(id) {
        router.post(route('creative-briefs.dismiss', id), {}, { preserveScroll: true });
    }

    /*
     * An account that has never had a brief showed five tabs reading 0, 0, 0, 0
     * and an "All" tab, above the words "No briefs found". Five ways to filter
     * nothing, and "found" reads as a search that failed — as if the briefs
     * exist and the filter is wrong.
     *
     * The two states are different and now say different things: nothing has
     * ever arrived (hide the filter, explain where briefs come from), versus
     * this particular tab is empty (keep the filter, offer the one that isn't).
     */
    const totalBriefs = ['pending', 'in_review', 'actioned', 'dismissed']
        .reduce((sum, key) => sum + (counts[key] ?? 0), 0);
    const neverHadAny = totalBriefs === 0;

    return (
        <AuthenticatedLayout>
            <Head title="Creative Briefs" />

            <div className="max-w-4xl mx-auto">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold text-gray-900">Creative Briefs</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        AI-generated creative briefs from your campaign agents. Review each one before we turn it into ads.
                    </p>
                </div>

                {flash?.success && (
                    <div className="mb-4 rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                {/* Status tabs — pointless before the first brief exists. */}
                <div className={`flex gap-1 border-b border-gray-200 mb-6 overflow-x-auto ${neverHadAny ? 'hidden' : ''}`}>
                    {tabs.map(tab => (
                        <button
                            key={tab.key}
                            onClick={() => switchTab(tab.key)}
                            className={`px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition ${
                                activeStatus === tab.key
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700'
                            }`}
                        >
                            {tab.label}
                            {tab.count !== null && (
                                <span className={`ml-1.5 inline-flex items-center justify-center w-5 h-5 text-xs rounded-full ${
                                    activeStatus === tab.key ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600'
                                }`}>
                                    {tab.count}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {briefs.data.length === 0 ? (
                    <div className="rounded-xl border border-gray-200 bg-white px-6 py-16 text-center">
                        <LightBulbIcon className="mx-auto h-12 w-12 text-gray-300" aria-hidden="true" />
                        {neverHadAny ? (
                            <>
                                <h2 className="mt-4 text-sm font-medium text-gray-900">No briefs yet</h2>
                                <p className="mx-auto mt-1 max-w-md text-sm text-gray-600">
                                    When a campaign has been running long enough to show which creative is
                                    working, the agents write up what to try next and it lands here. Nothing
                                    to do in the meantime — this fills itself in.
                                </p>
                            </>
                        ) : (
                            <>
                                <h2 className="mt-4 text-sm font-medium text-gray-900">
                                    Nothing {tabs.find(t => t.key === activeStatus)?.label.toLowerCase() ?? 'here'}
                                </h2>
                                <p className="mx-auto mt-1 max-w-md text-sm text-gray-600">
                                    You have {totalBriefs} brief{totalBriefs === 1 ? '' : 's'} under the other tabs.
                                </p>
                                <button
                                    type="button"
                                    onClick={() => switchTab('all')}
                                    className="mt-6 inline-flex min-h-[44px] items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                                >
                                    Show all briefs
                                </button>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="space-y-4">
                        {briefs.data.map(brief => (
                            <BriefCard
                                key={brief.id}
                                brief={brief}
                                onAction={handleAction}
                                onDismiss={handleDismiss}
                            />
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {briefs.last_page > 1 && (
                    <div className="mt-6 flex justify-center gap-2">
                        {briefs.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                className={`px-3 py-1.5 text-sm rounded border transition ${
                                    link.active
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : 'text-gray-600 border-gray-300 hover:bg-gray-50 disabled:opacity-40'
                                }`}
                            >
                                {decodePaginationLabel(link.label)}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
