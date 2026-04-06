import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

function MetricCard({ label, value, subValue, color }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <p className="text-xs text-gray-500 uppercase tracking-wide">{label}</p>
            <p className={`text-2xl font-bold mt-1 ${color || 'text-gray-900'}`}>{value}</p>
            {subValue && <p className="text-xs text-gray-400 mt-0.5">{subValue}</p>}
        </div>
    );
}

function FunnelBar({ stage, maxValue }) {
    const width = maxValue > 0 ? (stage.value / maxValue * 100) : 0;
    return (
        <div className="flex items-center gap-4">
            <span className="text-sm font-medium text-gray-700 w-28">{stage.name}</span>
            <div className="flex-1 bg-gray-200 rounded-full h-6 relative">
                <div
                    className="bg-gradient-to-r from-flame-orange-500 to-flame-orange-400 h-6 rounded-full flex items-center justify-end pr-2"
                    style={{ width: `${Math.max(width, 2)}%` }}
                >
                    <span className="text-xs text-white font-medium">{stage.value.toLocaleString()}</span>
                </div>
            </div>
            <span className="text-xs text-gray-500 w-14 text-right">{stage.rate}%</span>
        </div>
    );
}

export default function Index({ summary, timeSeries = [], funnel, days = 30 }) {
    const totals = summary?.totals || {};

    const changeDays = (d) => {
        router.get(route('analytics.index'), { days: d }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Analytics" />
            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Advanced Analytics</h1>
                            <p className="mt-1 text-sm text-gray-500">Cross-platform performance overview.</p>
                        </div>
                        <div className="flex gap-2">
                            {[7, 14, 30, 90].map((d) => (
                                <button
                                    key={d}
                                    onClick={() => changeDays(d)}
                                    className={`px-3 py-1.5 text-xs rounded-lg border ${days === d ? 'bg-flame-orange-600 text-white border-flame-orange-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'}`}
                                >
                                    {d}d
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="flex gap-2 mb-6">
                        <a href={route('analytics.cross-platform')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">Cross-Platform</a>
                        <a href={route('analytics.attribution')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">Attribution</a>
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4 mb-6">
                        <MetricCard label="Impressions" value={totals.impressions?.toLocaleString() ?? 0} />
                        <MetricCard label="Clicks" value={totals.clicks?.toLocaleString() ?? 0} />
                        <MetricCard label="CTR" value={`${totals.ctr ?? 0}%`} />
                        <MetricCard label="Cost" value={`$${(totals.cost ?? 0).toLocaleString()}`} />
                        <MetricCard label="CPC" value={`$${totals.cpc ?? 0}`} />
                        <MetricCard label="Conversions" value={totals.conversions?.toLocaleString() ?? 0} color="text-green-600" />
                        <MetricCard label="CPA" value={`$${totals.cpa ?? 0}`} />
                        <MetricCard label="ROAS" value={`${totals.roas ?? 0}x`} color="text-blue-600" />
                    </div>

                    {/* Funnel */}
                    {funnel && funnel.stages && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">Conversion Funnel</h2>
                            <div className="space-y-3">
                                {funnel.stages.map((stage, i) => (
                                    <FunnelBar key={i} stage={stage} maxValue={funnel.stages[0]?.value || 1} />
                                ))}
                            </div>
                            <div className="mt-4 grid grid-cols-3 gap-4 text-center pt-4 border-t">
                                <div>
                                    <p className="text-xs text-gray-500">CPM</p>
                                    <p className="text-sm font-bold">${funnel.cost_per_funnel_stage?.cpm ?? 0}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-gray-500">CPC</p>
                                    <p className="text-sm font-bold">${funnel.cost_per_funnel_stage?.cpc ?? 0}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-gray-500">CPA</p>
                                    <p className="text-sm font-bold">${funnel.cost_per_funnel_stage?.cpa ?? 0}</p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Daily Performance table */}
                    {timeSeries.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">Daily Performance</h2>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-gray-500 border-b">
                                            <th className="pb-2 font-medium">Date</th>
                                            <th className="pb-2 font-medium">Impressions</th>
                                            <th className="pb-2 font-medium">Clicks</th>
                                            <th className="pb-2 font-medium">Cost</th>
                                            <th className="pb-2 font-medium">Conversions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {timeSeries.slice(-14).reverse().map((row, i) => (
                                            <tr key={i} className="border-b border-gray-100">
                                                <td className="py-2 text-gray-900">{row.date}</td>
                                                <td className="py-2">{row.total?.impressions?.toLocaleString() ?? 0}</td>
                                                <td className="py-2">{row.total?.clicks?.toLocaleString() ?? 0}</td>
                                                <td className="py-2">${row.total?.cost?.toLocaleString() ?? 0}</td>
                                                <td className="py-2">{row.total?.conversions ?? 0}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
