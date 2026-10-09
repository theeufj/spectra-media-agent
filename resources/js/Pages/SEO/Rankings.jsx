import { useState } from 'react';
import WorkStatusBanner from '@/Components/WorkStatusBanner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, Link } from '@inertiajs/react';
import { Line } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Tooltip,
    Filler,
} from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Filler);

function TrendSparkline({ data }) {
    if (!data || data.length < 2) return <span className="text-xs text-gray-500">—</span>;

    const chartData = {
        labels: data.map(d => d.date),
        datasets: [{
            data: data.map(d => d.position),
            borderColor: '#ea580c',
            backgroundColor: 'rgba(234, 88, 12, 0.1)',
            borderWidth: 1.5,
            pointRadius: 0,
            fill: true,
            tension: 0.3,
        }],
    };

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { tooltip: { enabled: false } },
        scales: {
            x: { display: false },
            y: { display: false, reverse: true }, // lower position = better, so reverse
        },
    };

    return (
        <div className="w-24 h-8">
            <Line data={chartData} options={options} />
        </div>
    );
}

export default function Rankings({ summary, rankings = [], trends = {}, rankingRun = null }) {
    const [workBusy, setWorkBusy] = useState(['queued', 'running'].includes(rankingRun?.status));
    const [submitting, setSubmitting] = useState(false);
    return (
        <AuthenticatedLayout>
            <Head title="SEO Rankings" />
            <div className="py-8">
                <div className="mx-auto max-w-6xl">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                        <div>
                            <Link href={route('seo.index')} className="text-sm text-brand-dark hover:underline mb-1 inline-block">← Back to SEO</Link>
                            <h1 className="text-2xl font-bold text-gray-900">Organic search queries</h1>
                            <p className="mt-1 text-sm text-gray-500">Queries people actually use to find your website in Google. Paid ad keywords are managed separately.</p>
                        </div>
                        <button
                            disabled={workBusy || submitting}
                            onClick={() => { setSubmitting(true); router.post(route('seo.rankings.track'), {}, { preserveScroll: true, onFinish: () => setSubmitting(false) }); }}
                            className="px-4 py-2 bg-brand-dark text-white rounded-lg text-sm font-medium hover:bg-brand-darker"
                        >
                            Refresh measurements
                        </button>
                    </div>

                    <WorkStatusBanner initialRun={rankingRun} url={route('seo.work-status')} task="rankings" label="Rank tracking" reloadOnly={['summary', 'rankings', 'trends', 'rankingRun']} onBusyChange={setWorkBusy} />
                    <div className="mb-5 rounded-lg bg-blue-50 p-4 text-sm text-blue-900">
                        {summary?.source === 'google_search_console' ? (
                            <p>Search Console page-aggregated averages for the verified website host, excluding other subdomains, for {summary.reporting_start} to {summary.reporting_end}, saved {summary.measured_on}. Each point represents a rolling 28-day window, not a daily rank. Google omits some low-volume queries; no row does not mean a page is unranked. Changes compare overlapping reporting windows.</p>
                        ) : <p>Previous measurements are preserved. Their source and reporting window were not recorded. Refresh to collect first-party organic query data from Search Console.</p>}
                    </div>
                    {/* Summary */}
                    {summary && (
                        <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Keywords</p>
                                <p className="text-xl font-bold mt-1">{summary.total_keywords ?? 0}</p>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Mean query position</p>
                                <p className="text-xl font-bold mt-1">{summary.avg_position ? summary.avg_position.toFixed(1) : '—'}</p>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Average position ≤ 3</p>
                                <p className="text-xl font-bold mt-1 text-green-600">{summary.top_3_count ?? 0}</p>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 p-4">
                                <p className="text-xs text-gray-500">Average position ≤ 10</p>
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
                            <p className="text-sm text-gray-500 text-center py-8">No rankings tracked yet. Click "Refresh measurements" to start tracking.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-gray-500 border-b">
                                            <th className="pb-2 font-medium">Query</th>
                                            <th className="pb-2 font-medium">Average position</th>
                                            <th className="pb-2 font-medium">Clicks / impressions</th>
                                            <th className="pb-2 font-medium">Previous window</th>
                                            <th className="pb-2 font-medium">Change</th>
                                            <th className="pb-2 font-medium">Window trend</th>
                                            <th className="pb-2 font-medium">Primary landing page</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rankings.map((r, i) => (
                                            <tr key={i} className="border-b border-gray-100">
                                                <td className="py-2.5 font-medium text-gray-900">{r.keyword}</td>
                                                <td className="py-2.5">{r.average_position != null ? r.average_position.toFixed(1) : r.position ?? 'Not measured'}</td>
                                                <td className="py-2.5">{r.impressions != null ? `${r.clicks} / ${r.impressions}` : '—'}</td>
                                                <td className="py-2.5 text-gray-500">{r.previous_average_position != null ? r.previous_average_position.toFixed(1) : r.previous_position ?? '—'}</td>
                                                <td className="py-2.5">
                                                    {(r.average_change ?? r.change) > 0 && <span className="text-green-600 font-medium">↑ {(r.average_change ?? r.change).toFixed(1)}</span>}
                                                    {(r.average_change ?? r.change) < 0 && <span className="text-red-600 font-medium">↓ {Math.abs(r.average_change ?? r.change).toFixed(1)}</span>}
                                                    {(!(r.average_change ?? r.change) || (r.average_change ?? r.change) === 0) && <span className="text-gray-500">—</span>}
                                                </td>
                                                <td className="py-2.5">
                                                    <TrendSparkline data={trends[r.keyword]} />
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
