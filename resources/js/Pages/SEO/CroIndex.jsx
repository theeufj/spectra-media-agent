import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

function ScoreBadge({ score }) {
    if (score === null || score === undefined) return <span className="text-xs text-gray-400">—</span>;
    const color = score >= 70 ? 'bg-green-100 text-green-700' : score >= 50 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700';
    return <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${color}`}>{score}/100</span>;
}

function UpgradePrompt() {
    return (
        <div className="bg-gradient-to-br from-flame-orange-50 to-orange-50 rounded-lg border border-flame-orange-200 p-6 text-center mb-6">
            <h3 className="text-lg font-semibold text-gray-900">Audit Limit Reached</h3>
            <p className="text-sm text-gray-600 mt-1">You've used all 3 free CRO audits. Upgrade to unlock unlimited audits.</p>
            <p className="text-xs text-gray-400 mt-3">Available on Growth ($249/mo) and Agency plans</p>
            <a href={route('pricing')} className="mt-4 inline-flex items-center px-5 py-2 bg-flame-orange-600 text-white rounded-lg text-sm font-medium hover:bg-flame-orange-700 transition">
                View Plans
            </a>
        </div>
    );
}

export default function CroIndex({ audits, auditsUsed = 0, maxAudits, canRunAudit, domain }) {
    const { flash } = usePage().props;
    const [url, setUrl] = useState(domain ? `https://${domain}` : '');
    const [submitting, setSubmitting] = useState(false);

    const handleRun = (e) => {
        e.preventDefault();
        setSubmitting(true);
        router.post(route('seo.cro.run'), { url }, {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Landing Page CRO Audits" />
            <div className="py-8">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Landing Page CRO Audits</h1>
                            <p className="mt-1 text-sm text-gray-500">Analyze your landing pages for conversion rate optimization issues.</p>
                        </div>
                        {maxAudits !== null && (
                            <span className={`px-3 py-1 rounded-full text-xs font-semibold ${auditsUsed >= maxAudits ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'}`}>
                                {auditsUsed}/{maxAudits} audits used
                            </span>
                        )}
                    </div>

                    {/* Run Audit Form */}
                    {canRunAudit ? (
                        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-3">Audit a Landing Page</h2>
                            <p className="text-sm text-gray-500 mb-4">Enter a URL to analyze its performance, CTAs, and messaging for conversion optimization.</p>
                            <form onSubmit={handleRun} className="flex gap-3">
                                <input
                                    type="url"
                                    value={url}
                                    onChange={(e) => setUrl(e.target.value)}
                                    placeholder="https://example.com/landing-page"
                                    className="flex-1 rounded-lg border-gray-300 text-sm focus:ring-flame-orange-500 focus:border-flame-orange-500"
                                    required
                                />
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="px-6 py-2 bg-flame-orange-600 text-white rounded-lg text-sm font-medium hover:bg-flame-orange-700 disabled:opacity-50 transition"
                                >
                                    {submitting ? 'Starting...' : 'Run CRO Audit'}
                                </button>
                            </form>
                        </div>
                    ) : (
                        <UpgradePrompt />
                    )}

                    {/* Success Toast */}
                    {flash?.success && (
                        <div className="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                            <p className="text-sm text-green-700">{flash.success}</p>
                        </div>
                    )}

                    {/* Audits Table */}
                    <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-100">
                            <h2 className="text-sm font-semibold text-gray-900">Audit History</h2>
                        </div>
                        {audits.data && audits.data.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">URL</th>
                                            <th className="px-6 py-3 text-center text-[10px] font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                            <th className="px-6 py-3 text-center text-[10px] font-medium text-gray-500 uppercase tracking-wider">Issues</th>
                                            <th className="px-6 py-3 text-center text-[10px] font-medium text-gray-500 uppercase tracking-wider">CTAs</th>
                                            <th className="px-6 py-3 text-right text-[10px] font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {audits.data.map((audit) => (
                                            <tr key={audit.id} className="hover:bg-gray-50 cursor-pointer" onClick={() => router.visit(route('seo.cro.show', audit.id))}>
                                                <td className="px-6 py-4">
                                                    <p className="text-sm font-medium text-gray-900 truncate max-w-xs">{audit.url}</p>
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    <ScoreBadge score={audit.overall_score} />
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    <span className="text-sm text-gray-600">{(audit.issues || []).length}</span>
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    <span className="text-sm text-gray-600">{audit.cta_count ?? 0}</span>
                                                </td>
                                                <td className="px-6 py-4 text-right">
                                                    <span className="text-xs text-gray-400">{new Date(audit.created_at).toLocaleDateString()}</span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center">
                                <svg className="mx-auto h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                <p className="mt-3 text-sm text-gray-500">No CRO audits yet. Enter a URL above to get started.</p>
                                <p className="mt-1 text-xs text-gray-400">Audits also run automatically when you crawl pages via the Knowledge Base.</p>
                            </div>
                        )}

                        {/* Pagination */}
                        {audits.last_page > 1 && (
                            <div className="px-6 py-3 border-t border-gray-100 flex items-center justify-between">
                                <span className="text-xs text-gray-400">Page {audits.current_page} of {audits.last_page}</span>
                                <div className="flex gap-2">
                                    {audits.prev_page_url && (
                                        <Link href={audits.prev_page_url} className="px-3 py-1 text-xs border border-gray-200 rounded hover:bg-gray-50">Previous</Link>
                                    )}
                                    {audits.next_page_url && (
                                        <Link href={audits.next_page_url} className="px-3 py-1 text-xs border border-gray-200 rounded hover:bg-gray-50">Next</Link>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
