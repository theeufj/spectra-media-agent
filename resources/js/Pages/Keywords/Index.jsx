import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

function QSTrendChart({ data }) {
    if (!data || data.length === 0) return null;
    const max = 10;
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <h3 className="text-sm font-medium text-gray-900 mb-3">Quality Score Trend (30d)</h3>
            <div className="flex items-end gap-1 h-24">
                {data.map((d, i) => (
                    <div key={i} className="flex-1 flex flex-col items-center">
                        <div
                            className={`w-full rounded-t ${d.avg_qs >= 7 ? 'bg-green-400' : d.avg_qs >= 4 ? 'bg-yellow-400' : 'bg-red-400'}`}
                            style={{ height: `${(d.avg_qs / max) * 100}%` }}
                            title={`${d.date}: ${Number(d.avg_qs).toFixed(1)}`}
                        />
                    </div>
                ))}
            </div>
        </div>
    );
}

function StatCard({ label, value, color = 'gray' }) {
    const colors = {
        gray: 'bg-gray-50 text-gray-900',
        green: 'bg-green-50 text-green-700',
        red: 'bg-red-50 text-red-700',
        blue: 'bg-blue-50 text-blue-700',
    };
    return (
        <div className={`rounded-lg p-4 ${colors[color]}`}>
            <p className="text-xs font-medium opacity-70">{label}</p>
            <p className="text-2xl font-bold">{value?.toLocaleString() ?? '—'}</p>
        </div>
    );
}

export default function Index({ keywords, stats, qsTrends }) {
    const [selected, setSelected] = useState([]);
    const [bulkAction, setBulkAction] = useState('');

    const toggleSelect = (id) => {
        setSelected(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
    };

    const toggleAll = () => {
        if (selected.length === keywords.data.length) setSelected([]);
        else setSelected(keywords.data.map(k => k.id));
    };

    const handleBulk = () => {
        if (!bulkAction || selected.length === 0) return;
        router.post(route('keywords.bulk'), { keyword_ids: selected, action: bulkAction }, {
            preserveScroll: true,
            onSuccess: () => { setSelected([]); setBulkAction(''); },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Keywords" />
            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Keyword Portfolio</h1>
                            <p className="mt-1 text-sm text-gray-500">All keywords across your campaigns with QS tracking.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <a href={route('keywords.research')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700">
                                Research Keywords
                            </a>
                            <a href={route('keywords.competitor-gap')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                                Competitor Gap
                            </a>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                        <StatCard label="Total Keywords" value={stats.total} color="blue" />
                        <StatCard label="Active" value={stats.active} color="green" />
                        <StatCard label="Low QS (<5)" value={stats.low_qs} color="red" />
                        <QSTrendChart data={qsTrends} />
                    </div>

                    {/* Bulk actions */}
                    {selected.length > 0 && (
                        <div className="flex items-center gap-2 mb-4 p-3 bg-blue-50 rounded-lg">
                            <span className="text-sm text-blue-700 font-medium">{selected.length} selected</span>
                            <select value={bulkAction} onChange={e => setBulkAction(e.target.value)} className="text-sm rounded border-gray-300">
                                <option value="">Action...</option>
                                <option value="pause">Pause</option>
                                <option value="activate">Activate</option>
                                <option value="remove">Remove</option>
                            </select>
                            <button onClick={handleBulk} disabled={!bulkAction} className="px-3 py-1 text-sm bg-blue-600 text-white rounded-lg disabled:opacity-50">Apply</button>
                        </div>
                    )}

                    <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-3"><input type="checkbox" onChange={toggleAll} checked={selected.length === keywords.data?.length && keywords.data?.length > 0} className="rounded border-gray-300" /></th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Keyword</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Match</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">QS</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Campaign</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {keywords.data?.map(kw => (
                                    <tr key={kw.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3"><input type="checkbox" checked={selected.includes(kw.id)} onChange={() => toggleSelect(kw.id)} className="rounded border-gray-300" /></td>
                                        <td className="px-4 py-3 text-sm font-medium text-gray-900">{kw.keyword_text}</td>
                                        <td className="px-4 py-3"><span className="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600">{kw.match_type}</span></td>
                                        <td className="px-4 py-3">
                                            <span className={`text-xs px-2 py-0.5 rounded ${kw.status === 'active' ? 'bg-green-100 text-green-700' : kw.status === 'paused' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'}`}>{kw.status}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {kw.quality_score != null ? (
                                                <span className={`text-sm font-bold ${kw.quality_score >= 7 ? 'text-green-600' : kw.quality_score >= 4 ? 'text-yellow-600' : 'text-red-600'}`}>{kw.quality_score}/10</span>
                                            ) : <span className="text-xs text-gray-400">—</span>}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500">{kw.source}</td>
                                        <td className="px-4 py-3 text-xs text-gray-500">{kw.campaign?.name ?? '—'}</td>
                                    </tr>
                                ))}
                                {(!keywords.data || keywords.data.length === 0) && (
                                    <tr><td colSpan={7} className="px-4 py-12 text-center text-sm text-gray-500">No keywords yet. Start by researching keywords.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
