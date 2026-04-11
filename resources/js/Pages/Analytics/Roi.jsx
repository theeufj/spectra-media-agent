import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useState } from 'react';

const platformLabels = {
    google: 'Google Ads',
    facebook: 'Facebook Ads',
    microsoft: 'Microsoft Ads',
    linkedin: 'LinkedIn Ads',
};

const platformColors = {
    google: '#4285F4',
    facebook: '#1877F2',
    microsoft: '#00A4EF',
    linkedin: '#0A66C2',
};

function KpiCard({ label, value, sub, trend }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <p className="text-sm text-gray-500">{label}</p>
            <p className="text-2xl font-bold text-gray-900 mt-1">{value}</p>
            {sub && <p className="text-xs text-gray-400 mt-1">{sub}</p>}
            {trend !== undefined && (
                <p className={`text-xs mt-1 font-medium ${trend >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                    {trend >= 0 ? '↑' : '↓'} {Math.abs(trend)}% vs previous period
                </p>
            )}
        </div>
    );
}

function SpendBar({ platforms }) {
    const total = Object.values(platforms).reduce((s, p) => s + p.cost, 0);
    if (total === 0) return null;

    return (
        <div className="space-y-2">
            <div className="flex h-6 rounded-full overflow-hidden">
                {Object.entries(platforms).map(([name, data]) => {
                    const pct = (data.cost / total) * 100;
                    return (
                        <div
                            key={name}
                            className="h-full"
                            style={{ width: `${pct}%`, backgroundColor: platformColors[name] || '#6B7280' }}
                            title={`${platformLabels[name]}: $${data.cost.toLocaleString()} (${pct.toFixed(1)}%)`}
                        />
                    );
                })}
            </div>
            <div className="flex flex-wrap gap-4 text-xs">
                {Object.entries(platforms).map(([name, data]) => (
                    <div key={name} className="flex items-center gap-1.5">
                        <span className="w-3 h-3 rounded-full" style={{ backgroundColor: platformColors[name] }} />
                        <span className="text-gray-600">{platformLabels[name]}: ${data.cost.toLocaleString()}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DailyChart({ data }) {
    if (!data || data.length === 0) return null;

    const maxVal = Math.max(...data.map(d => Math.max(d.cost, d.revenue)));
    const chartHeight = 200;

    return (
        <div className="overflow-x-auto">
            <div className="flex items-end gap-1 min-w-fit" style={{ height: chartHeight + 40 }}>
                {data.map((day, i) => {
                    const costH = maxVal > 0 ? (day.cost / maxVal) * chartHeight : 0;
                    const revH = maxVal > 0 ? (day.revenue / maxVal) * chartHeight : 0;

                    return (
                        <div key={i} className="flex flex-col items-center gap-0.5 group relative" style={{ width: Math.max(16, 800 / data.length) }}>
                            <div className="flex items-end gap-px">
                                <div
                                    className="bg-red-300 rounded-t"
                                    style={{ height: costH, width: 6 }}
                                    title={`Cost: $${day.cost}`}
                                />
                                <div
                                    className="bg-green-400 rounded-t"
                                    style={{ height: revH, width: 6 }}
                                    title={`Revenue: $${day.revenue}`}
                                />
                            </div>
                            {i % Math.ceil(data.length / 10) === 0 && (
                                <span className="text-[10px] text-gray-400 mt-1 rotate-[-45deg] origin-top-left whitespace-nowrap">
                                    {day.date.split('-').slice(1).join('/')}
                                </span>
                            )}
                        </div>
                    );
                })}
            </div>
            <div className="flex gap-4 mt-4 text-xs text-gray-500">
                <span className="flex items-center gap-1"><span className="w-3 h-3 bg-red-300 rounded" /> Cost</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 bg-green-400 rounded" /> Revenue</span>
            </div>
        </div>
    );
}

export default function Roi({ days, platformData, campaignBreakdown, dailyTrend, projections }) {
    const [selectedDays, setSelectedDays] = useState(days);

    const handleDaysChange = (newDays) => {
        setSelectedDays(newDays);
        router.get(route('analytics.roi'), { days: newDays }, { preserveState: true });
    };

    const totalCost = Object.values(platformData).reduce((s, p) => s + p.cost, 0);
    const totalRevenue = Object.values(platformData).reduce((s, p) => s + p.revenue, 0);
    const totalConversions = Object.values(platformData).reduce((s, p) => s + p.conversions, 0);
    const overallRoas = totalCost > 0 ? (totalRevenue / totalCost).toFixed(2) : 0;
    const avgCpa = totalConversions > 0 ? (totalCost / totalConversions).toFixed(2) : 0;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">ROI Dashboard</h2>}
        >
            <Head title="ROI Dashboard" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                    {/* Time Range Selector */}
                    <div className="flex justify-end">
                        <div className="inline-flex rounded-lg border border-gray-200 bg-white">
                            {[7, 14, 30, 90].map((d) => (
                                <button
                                    key={d}
                                    onClick={() => handleDaysChange(d)}
                                    className={`px-4 py-2 text-sm font-medium transition ${
                                        selectedDays === d
                                            ? 'bg-indigo-600 text-white rounded-lg'
                                            : 'text-gray-600 hover:bg-gray-50'
                                    }`}
                                >
                                    {d}d
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                        <KpiCard label="Total Ad Spend" value={`$${totalCost.toLocaleString()}`} sub={`${selectedDays} days`} />
                        <KpiCard label="Total Revenue" value={`$${totalRevenue.toLocaleString()}`} />
                        <KpiCard label="ROAS" value={`${overallRoas}x`} sub={overallRoas >= 3 ? 'Strong' : overallRoas >= 1 ? 'Moderate' : 'Needs attention'} />
                        <KpiCard label="Conversions" value={totalConversions.toLocaleString()} />
                        <KpiCard label="Avg CPA" value={`$${avgCpa}`} />
                    </div>

                    {/* Spend Allocation */}
                    {Object.keys(platformData).length > 0 && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">Spend Allocation</h3>
                            <SpendBar platforms={platformData} />
                        </div>
                    )}

                    {/* Daily Trend Chart */}
                    {dailyTrend.length > 0 && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">Daily Spend vs Revenue</h3>
                            <DailyChart data={dailyTrend} />
                        </div>
                    )}

                    {/* Projections */}
                    {projections && (
                        <div className="bg-gradient-to-r from-indigo-50 to-violet-50 rounded-xl border border-indigo-200 p-6">
                            <h3 className="text-lg font-semibold text-indigo-900 mb-4">Spending Projections</h3>
                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <div>
                                    <p className="text-sm text-indigo-600">Daily Avg Spend</p>
                                    <p className="text-xl font-bold text-indigo-900">${projections.daily_avg_spend}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-indigo-600">Monthly Projected Spend</p>
                                    <p className="text-xl font-bold text-indigo-900">${projections.monthly_projected_spend?.toLocaleString()}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-indigo-600">Monthly Projected Revenue</p>
                                    <p className="text-xl font-bold text-green-700">${projections.monthly_projected_revenue?.toLocaleString()}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-indigo-600">Monthly Projected Profit</p>
                                    <p className={`text-xl font-bold ${projections.monthly_projected_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                        ${projections.monthly_projected_profit?.toLocaleString()}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-indigo-600">Budget Utilization</p>
                                    <p className="text-xl font-bold text-indigo-900">{projections.budget_utilization}%</p>
                                </div>
                                <div>
                                    <p className="text-sm text-indigo-600">Daily Budget (Total)</p>
                                    <p className="text-xl font-bold text-indigo-900">${projections.daily_budget_total}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-indigo-600">Quarterly Projected Spend</p>
                                    <p className="text-xl font-bold text-indigo-900">${projections.quarterly_projected_spend?.toLocaleString()}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-indigo-600">Quarterly Projected Revenue</p>
                                    <p className="text-xl font-bold text-green-700">${projections.quarterly_projected_revenue?.toLocaleString()}</p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Campaign Breakdown Table */}
                    {campaignBreakdown.length > 0 && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                            <div className="p-6 border-b border-gray-200">
                                <h3 className="text-lg font-semibold text-gray-900">Campaign ROI Breakdown</h3>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-gray-50 text-gray-500 text-xs uppercase">
                                        <tr>
                                            <th className="py-3 px-4 text-left">Campaign</th>
                                            <th className="py-3 px-4 text-right">Cost</th>
                                            <th className="py-3 px-4 text-right">Revenue</th>
                                            <th className="py-3 px-4 text-right">Conversions</th>
                                            <th className="py-3 px-4 text-right">ROAS</th>
                                            <th className="py-3 px-4 text-right">CPA</th>
                                            <th className="py-3 px-4 text-right">Budget Used</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {campaignBreakdown.map((c) => (
                                            <tr key={c.id} className="border-b border-gray-100 hover:bg-gray-50">
                                                <td className="py-3 px-4 font-medium text-gray-900">{c.name}</td>
                                                <td className="py-3 px-4 text-right">${c.cost.toLocaleString()}</td>
                                                <td className="py-3 px-4 text-right">${c.revenue.toLocaleString()}</td>
                                                <td className="py-3 px-4 text-right">{c.conversions}</td>
                                                <td className="py-3 px-4 text-right">
                                                    <span className={c.roas >= 2 ? 'text-green-600 font-semibold' : c.roas >= 1 ? 'text-yellow-600' : 'text-red-600 font-semibold'}>
                                                        {c.roas}x
                                                    </span>
                                                </td>
                                                <td className="py-3 px-4 text-right">${c.cpa}</td>
                                                <td className="py-3 px-4 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <div className="w-16 bg-gray-200 rounded-full h-2">
                                                            <div
                                                                className={`h-2 rounded-full ${c.budget_utilization > 100 ? 'bg-red-500' : 'bg-indigo-500'}`}
                                                                style={{ width: `${Math.min(100, c.budget_utilization)}%` }}
                                                            />
                                                        </div>
                                                        <span className="text-xs text-gray-500">{c.budget_utilization}%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Empty State */}
                    {Object.keys(platformData).length === 0 && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
                            <p className="text-gray-500 mb-4">No performance data found for the selected period.</p>
                            <a href={route('sandbox.index')} className="text-indigo-600 hover:underline text-sm">
                                Try launching a sandbox to see demo data →
                            </a>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
