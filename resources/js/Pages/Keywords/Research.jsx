import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FormErrorSummary from '@/Components/FormErrorSummary';
import { brandTint } from '@/Components/Marketing/Hero';

function ClusterCard({ cluster }) {
    const intentColors = { transactional: 'bg-green-100 text-green-700', commercial: 'bg-blue-100 text-blue-700', informational: 'bg-gray-100 text-gray-600', navigational: 'bg-purple-100 text-purple-700' };
    const funnelColors = { decision: 'bg-green-100 text-green-700', consideration: 'bg-yellow-100 text-yellow-700', awareness: 'bg-blue-100 text-blue-700' };

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <div className="flex items-center justify-between mb-2">
                <h4 className="text-sm font-semibold text-gray-900">{cluster.cluster_name}</h4>
                {cluster.recommended_ad_group && <span className="text-xs px-2 py-0.5 rounded text-brand-darker" style={{ backgroundColor: brandTint(20) }}>Ad Group ✓</span>}
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

export default function Research({ customer, campaigns = [] }) {
    const { props } = usePage();
    const results = props.research_results || null;
    const { data: form, setData: setForm, post, processing: loading, errors } = useForm({ seed_keywords: '', competitor_url: '', landing_page: customer?.website || '', max_keywords: 20 });
    const addForm = useForm({ keywords: [], campaign_id: '', source: 'research' });
    const [selected, setSelected] = useState([]);
    useEffect(() => setSelected([]), [results]);
    const handleSubmit = event => {
        event.preventDefault();
        post(route('keywords.do-research'), { preserveScroll: true });
    };
    const handleAddSelected = () => {
        addForm.transform(values => ({ ...values, campaign_id: values.campaign_id || null, keywords: results.keywords.filter((keyword, index) => selected.includes(index)).map(keyword => ({ ...keyword, match_type: keyword.match_type || 'BROAD' })) }));
        addForm.post(route('keywords.add-to-campaign'), { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Keyword Research" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl">
                    <div className="mb-6">
                        <h1 className="text-2xl font-bold text-gray-900">Keyword Research</h1>
                        <p className="mt-1 text-sm text-gray-500">We find the terms people actually search for in your market, using Google’s own search data.</p>
                    </div>

                    <form onSubmit={handleSubmit} className="bg-white rounded-lg border border-gray-200 p-6 mb-8">
                        <FormErrorSummary errors={errors} />
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label htmlFor="seed_keywords" className="block text-sm font-medium text-gray-700 mb-1">Seed Keywords</label>
                                <textarea id="seed_keywords" value={form.seed_keywords} onChange={e => setForm({...form, seed_keywords: e.target.value})} placeholder="e.g. plumber, emergency plumbing, drain repair" rows={3} className="w-full rounded-lg border-gray-300 text-sm" />
                            </div>
                            <div className="space-y-3">
                                <div>
                                    <label htmlFor="landing_page" className="block text-sm font-medium text-gray-700 mb-1">Landing Page URL</label>
                                    <input id="landing_page" type="url" value={form.landing_page} onChange={e => setForm({...form, landing_page: e.target.value})} placeholder="https://example.com" className="w-full rounded-lg border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <label htmlFor="competitor_url" className="block text-sm font-medium text-gray-700 mb-1">Competitor URL</label>
                                    <input id="competitor_url" type="url" value={form.competitor_url} onChange={e => setForm({...form, competitor_url: e.target.value})} placeholder="https://competitor.com" className="w-full rounded-lg border-gray-300 text-sm" />
                                </div>
                            </div>
                        </div>
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <label htmlFor="max_keywords" className="text-sm text-gray-500">Max:</label>
                                <select id="max_keywords" value={form.max_keywords} onChange={e => setForm({...form, max_keywords: parseInt(e.target.value)})} className="rounded-lg border-gray-300 text-sm">
                                    <option value={10}>10</option><option value={20}>20</option><option value={30}>30</option><option value={50}>50</option>
                                </select>
                            </div>
                            <button type="submit" disabled={loading} className="px-6 py-2 text-sm font-medium text-white bg-brand-dark rounded-lg hover:bg-brand-darker disabled:opacity-50">
                                {loading ? 'Researching...' : 'Research Keywords'}
                            </button>
                        </div>
                    </form>

                    {results && (
                        <>
                            {/* Keywords Table */}
                            <h2 className="mb-3 text-lg font-semibold text-gray-900">{results.keywords?.length || 0} keywords found</h2>
                            <FormErrorSummary errors={addForm.errors} />
                            <div className="mb-4 flex flex-wrap items-center gap-3">
                                <button onClick={() => setSelected(selected.length === (results.keywords?.length ?? 0) ? [] : results.keywords.map((keyword, index) => index))} className="rounded border px-3 py-2 text-sm">{selected.length === results.keywords?.length ? 'Clear selection' : 'Select all'}</button>
                                <label htmlFor="campaign_id" className="text-sm text-gray-700">Save to</label>
                                <select id="campaign_id" value={addForm.data.campaign_id} onChange={event => addForm.setData('campaign_id', event.target.value)} className="max-w-full rounded-lg border-gray-300 text-sm">
                                    <option value="">Keyword portfolio</option>{campaigns.map(campaign => <option key={campaign.id} value={campaign.id}>{campaign.name}</option>)}
                                </select>
                                <button onClick={handleAddSelected} disabled={!selected.length || addForm.processing} className="rounded-lg bg-brand-dark px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{addForm.processing ? 'Saving…' : `Save ${selected.length} selected`}</button>
                                <Link href={route('keywords.index')} className="text-sm text-brand-dark underline">Open portfolio</Link>
                            </div>
                            <p className="mb-3 text-xs text-gray-500">Saved keywords are available for planning. Live campaign changes still require review.</p>
                            <div className="bg-white rounded-lg border border-gray-200 overflow-x-auto mb-8">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-3 py-3"><span className="sr-only">Select keyword</span></th>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Keyword</th>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Match</th>
                                            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Volume</th>
                                            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Competition</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200">
                                        {results.keywords?.map((kw, i) => (
                                            <tr key={i} className="hover:bg-gray-50">
                                                <td className="px-3 py-3"><input type="checkbox" aria-label={`Select ${kw.text}`} checked={selected.includes(i)} onChange={event => setSelected(event.target.checked ? [...selected, i] : selected.filter(index => index !== i))} className="rounded border-gray-300 text-brand-dark" /></td>
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
