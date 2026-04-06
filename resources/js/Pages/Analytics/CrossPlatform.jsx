import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

const PLATFORM_COLORS = {
    Google: 'bg-blue-500',
    Facebook: 'bg-indigo-500',
    Microsoft: 'bg-teal-500',
    LinkedIn: 'bg-sky-500',
};

function PlatformBar({ platform, metric, maxValue }) {
    const width = maxValue > 0 ? (platform[metric] / maxValue * 100) : 0;
    const color = PLATFORM_COLORS[platform.platform] || 'bg-gray-400';
    return (
        <div className="flex items-center gap-3">
            <span className="text-xs text-gray-600 w-20">{platform.platform}</span>
            <div className="flex-1 bg-gray-200 rounded-full h-4">
                <div className={`${color} h-4 rounded-full`} style={{ width: `${Math.max(width, 1)}%` }} />
            </div>
            <span className="text-xs font-medium text-gray-900 w-20 text-right">
                {metric === 'cost' ? `$${platform[metric]?.toLocaleString()}` : metric === 'roas' ? `${platform[metric]}x` : platform[metric]?.toLocaleString()}
            </span>
        </div>
    );
}

export default function CrossPlatform({ comparison = [], timeSeries = [], days = 30 }) {
    const changeDays = (d) => {
        router.get(route('analytics.cross-platform'), { days: d }, { preserveState: true });
    };

    const metrics = ['impressions', 'clicks', 'cost', 'conversions', 'roas'];
    const metricLabels = { impressions: 'Impressions', clicks: 'Clicks', cost: 'Spend', conversions: 'Conversions', roas: 'ROAS' };

    return (
        <AuthenticatedLayout>
            <Head title="Cross-Platform Analytics" />
            <div className="py-8">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <a href={route('analytics.index')} className="text-sm text-flame-orange-600 hover:underline mb-1 inline-block">← Back to Analytics</a>
                            <h1 className="text-2xl font-bold text-gray-900">Cross-Platform Comparison</h1>
                            <p className="mt-1 text-sm text-gray-500">Compare performance across Google, Facebook, Microsoft, and LinkedIn.</p>
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

                    {/* Spend Share */}
                    {comparison.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">Spend Distribution</h2>
                            <div className="flex gap-2 mb-4">
                                {comparison.filter(p => p.spend_share > 0).map((p) => (
                                    <div key={p.platform} className="flex items-center gap-2">
                                        <div className={`w-3 h-3 rounded-full ${PLATFORM_COLORS[p.platform] || 'bg-gray-400'}`} />
                                        <span className="text-sm text-gray-700">{p.platform}: {p.spend_share}%</span>
                                    </div>
                                ))}
                            </div>
                            <div className="flex h-4 rounded-full overflow-hidden bg-gray-200">
                                {comparison.filter(p => p.spend_share > 0).map((p) => (
                                    <div
                                        key={p.platform}
                                        className={PLATFORM_COLORS[p.platform] || 'bg-gray-400'}
                                        style={{ width: `${p.spend_share}%` }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Metric comparisons */}
                    {comparison.length > 0 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            {metrics.map((metric) => {
                                const maxVal = Math.max(...comparison.map((p) => p[metric] || 0));
                                return (
                                    <div key={metric} className="bg-white rounded-lg border border-gray-200 p-5">
                                        <h3 className="text-sm font-semibold text-gray-900 mb-3">{metricLabels[metric]}</h3>
                                        <div className="space-y-2">
                                            {comparison.map((p) => (
                                                <PlatformBar key={p.platform} platform={p} metric={metric} maxValue={maxVal} />
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Detailed table */}
                    {comparison.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">Platform Details</h2>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-gray-500 border-b">
                                            <th className="pb-2 font-medium">Platform</th>
                                            <th className="pb-2 font-medium">Impressions</th>
                                            <th className="pb-2 font-medium">Clicks</th>
                                            <th className="pb-2 font-medium">Spend</th>
                                            <th className="pb-2 font-medium">Conversions</th>
                                            <th className="pb-2 font-medium">ROAS</th>
                                            <th className="pb-2 font-medium">Conv. Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {comparison.map((p) => (
                                            <tr key={p.platform} className="border-b border-gray-100">
                                                <td className="py-2.5 font-medium text-gray-900">
                                                    <span className="flex items-center gap-2">
                                                        <span className={`w-2 h-2 rounded-full ${PLATFORM_COLORS[p.platform] || 'bg-gray-400'}`} />
                                                        {p.platform}
                                                    </span>
                                                </td>
                                                <td className="py-2.5">{p.impressions?.toLocaleString()}</td>
                                                <td className="py-2.5">{p.clicks?.toLocaleString()}</td>
                                                <td className="py-2.5">${p.cost?.toLocaleString()}</td>
                                                <td className="py-2.5">{p.conversions}</td>
                                                <td className="py-2.5">{p.roas}x</td>
                                                <td className="py-2.5">{p.conversion_share}%</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {comparison.length === 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <p className="text-gray-500">No cross-platform data available yet. Data will appear once campaigns are running.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
