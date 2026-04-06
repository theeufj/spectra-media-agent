import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function ClusterCard({ cluster }) {
    const intentColors = { transactional: 'bg-green-100 text-green-700', commercial: 'bg-blue-100 text-blue-700', informational: 'bg-gray-100 text-gray-600', navigational: 'bg-purple-100 text-purple-700' };
    const funnelColors = { decision: 'bg-green-100 text-green-700', consideration: 'bg-yellow-100 text-yellow-700', awareness: 'bg-blue-100 text-blue-700' };

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <div className="flex items-center justify-between mb-2">
                <h4 className="text-sm font-semibold text-gray-900">{cluster.cluster_name}</h4>
                {cluster.recommended_ad_group && <span className="text-xs px-2 py-0.5 rounded bg-flame-orange-100 text-flame-orange-700">Ad Group ✓</span>}
            </div>
            <div className="flex gap-2 mb-3">
                <span className={`text-xs px-2 py-0.5 rounded ${intentColors[cluster.intent] || 'bg-gray-100 text-gray-600'}`}>{cluster.intent}</span>
                <span className={`text-xs px-2 py-0.5 rounded ${funnelColors[cluster.funnel_stage] || 'bg-gray-100 text-gray-600'}`}>{cluster.funnel_stage}</span>
            </div>
            <div className="flex flex-wrap gap-1">
                {cluster.keywords?.map((kw, i) => (
                    <span key={i} className="text-xs bg-gray-50 border border-gray-200 rounded px-2 py-0.5 text-gray-700">{kw}</span>
                ))}
            </div>
        </div>
    );
}

export default function Research({ customer }) {
    const { props } = usePage();
    const results = props.research_results || null;
    const [form, setForm] = useState({ seed_keywords: '', competitor_url: '', landing_page: customer?.website_url || '', max_keywords: 20 });
    const [loading, setLoading] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        setLoading(true);
        router.post(route('keywords.do-research'), form, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
        });
    };

    const handleAddAll = () => {
        if (!results?.keywords) return;
        router.post(route('keywords.add-to-campaign'), {
            keywords: results.keywords.map(kw => ({
                text: kw.text,
                match_type: kw.match_type || 'BROAD',
                avg_monthly_searches: kw.avg_monthly_searches,
                competition_index: kw.competition_index,
            })),
            source: 'research',
        }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Keyword Research" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6">
                        <h1 className="text-2xl font-bold text-gray-900">Keyword Research</h1>
                        <p className="mt-1 text-sm text-gray-500">AI-powered keyword discovery using Google Keyword Planner + Gemini.</p>
                    </div>

                    <form onSubmit={handleSubmit} className="bg-white rounded-lg border border-gray-200 p-6 mb-8">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Seed Keywords</label>
                                <textarea value={form.seed_keywords} onChange={e => setForm({...form, seed_keywords: e.target.value})} placeholder="e.g. plumber, emergency plumbing, drain repair" rows={3} className="w-full rounded-lg border-gray-300 text-sm" />
                            </div>
                            <div className="space-y-3">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Landing Page URL</label>
                                    <input type="url" value={form.landing_page} onChange={e => setForm({...form, landing_page: e.target.value})} placeholder="https://example.com" className="w-full rounded-lg border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Competitor URL</label>
                                    <input type="url" value={form.competitor_url} onChange={e => setForm({...form, competitor_url: e.target.value})} placeholder="https://competitor.com" className="w-full rounded-lg border-gray-300 text-sm" />
                                </div>
                            </div>
                        </div>
                        <div className="mt-4 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <label className="text-sm text-gray-500">Max:</label>
                                <select value={form.max_keywords} onChange={e => setForm({...form, max_keywords: parseInt(e.target.value)})} className="rounded-lg border-gray-300 text-sm">
                                    <option value={10}>10</option><option value={20}>20</option><option value={30}>30</option><option value={50}>50</option>
                                </select>
                            </div>
                            <button type="submit" disabled={loading} className="px-6 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 disabled:opacity-50">
                                {loading ? 'Researching...' : 'Research Keywords'}
                            </button>
                        </div>
                    </form>

                    {results && (
                        <>
                            {/* Keywords Table */}
                            <div className="flex items-center justify-between mb-4">
                                <h2 className="text-lg font-semibold text-gray-900">{results.keywords?.length || 0} Keywords Found</h2>
                                <button onClick={handleAddAll} className="px-4 py-2 text-sm font-medium text-flame-orange-700 bg-flame-orange-50 rounded-lg hover:bg-flame-orange-100">
                                    Add All to Portfolio
                                </button>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 overflow-hidden mb-8">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Keyword</th>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Match</th>
                                            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Volume</th>
                                            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Competition</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200">
                                        {results.keywords?.map((kw, i) => (
                                            <tr key={i} className="hover:bg-gray-50">
                                                <td className="px-4 py-3 text-sm font-medium text-gray-900">{kw.text}</td>
                                                <td className="px-4 py-3"><span className="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600">{kw.match_type}</span></td>
                                                <td className="px-4 py-3 text-right text-sm text-gray-600">{kw.avg_monthly_searches?.toLocaleString() ?? '—'}</td>
                                                <td className="px-4 py-3 text-right text-sm text-gray-600">{kw.competition_index ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Clusters */}
                            {results.clusters?.length > 0 && (
                                <div className="mb-8">
                                    <h2 className="text-lg font-semibold text-gray-900 mb-4">AI Keyword Clusters</h2>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        {results.clusters.map((c, i) => <ClusterCard key={i} cluster={c} />)}
                                    </div>
                                </div>
                            )}

                            {/* Negatives */}
                            {results.negative_keywords?.length > 0 && (
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-900 mb-3">Suggested Negative Keywords</h2>
                                    <div className="flex flex-wrap gap-2">
                                        {results.negative_keywords.map((nk, i) => (
                                            <span key={i} className="px-3 py-1 text-sm bg-red-50 text-red-700 rounded-full border border-red-200">{nk}</span>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
