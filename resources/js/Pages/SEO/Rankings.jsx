import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function Rankings({ summary, rankings = [], trends = {} }) {
    return (
        <AuthenticatedLayout>
            <Head title="SEO Rankings" />
            <div className="py-8">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <a href={route('seo.index')} className="text-sm text-flame-orange-600 hover:underline mb-1 inline-block">← Back to SEO</a>
                            <h1 className="text-2xl font-bold text-gray-900">Keyword Rankings</h1>
                            <p className="mt-1 text-sm text-gray-500">Track your keyword positions across search engines.</p>
                        </div>
                        <button
                            onClick={() => router.post(route('seo.rankings.track'), {}, { preserveScroll: true })}
                            className="px-4 py-2 bg-flame-orange-600 text-white rounded-lg text-sm font-medium hover:bg-flame-orange-700"
                        >
                            Track Now
                        </button>
                    </div>

                    {/* Summary */}
                    {summary && (
                        <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Keywords</p>
                                <p className="text-xl font-bold mt-1">{summary.total_keywords ?? 0}</p>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Avg. Position</p>
                                <p className="text-xl font-bold mt-1">{summary.avg_position ? summary.avg_position.toFixed(1) : '—'}</p>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Top 3</p>
                                <p className="text-xl font-bold mt-1 text-green-600">{summary.top_3_count ?? 0}</p>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Top 10</p>
                                <p className="text-xl font-bold mt-1 text-blue-600">{summary.top_10_count ?? 0}</p>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Improved</p>
                                <p className="text-xl font-bold mt-1 text-green-600">{summary.improved_count ?? 0}</p>
                            </div>
                        </div>
                    )}

                    {/* Rankings Table */}
                    <div className="bg-white rounded-lg border border-gray-200 p-6">
                        {rankings.length === 0 ? (
                            <p className="text-sm text-gray-500 text-center py-8">No rankings tracked yet. Click "Track Now" to start tracking.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-gray-500 border-b">
                                            <th className="pb-2 font-medium">Keyword</th>
                                            <th className="pb-2 font-medium">Position</th>
                                            <th className="pb-2 font-medium">Previous</th>
                                            <th className="pb-2 font-medium">Change</th>
                                            <th className="pb-2 font-medium">URL</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rankings.map((r, i) => (
                                            <tr key={i} className="border-b border-gray-100">
                                                <td className="py-2.5 font-medium text-gray-900">{r.keyword}</td>
                                                <td className="py-2.5">{r.position || '100+'}</td>
                                                <td className="py-2.5 text-gray-500">{r.previous_position || '—'}</td>
                                                <td className="py-2.5">
                                                    {r.change > 0 && <span className="text-green-600 font-medium">↑ {r.change}</span>}
                                                    {r.change < 0 && <span className="text-red-600 font-medium">↓ {Math.abs(r.change)}</span>}
                                                    {(!r.change || r.change === 0) && <span className="text-gray-400">—</span>}
                                                </td>
                                                <td className="py-2.5 text-gray-500 truncate max-w-xs text-xs">{r.url || '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
