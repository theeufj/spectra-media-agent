import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useState, useEffect } from 'react';

const statusColors = {
    completed: 'bg-green-100 text-green-800',
    error: 'bg-red-100 text-red-800',
    failed: 'bg-red-100 text-red-800',
    no_data: 'bg-yellow-100 text-yellow-800',
};

const platformLabels = {
    google: 'Google Ads',
    facebook: 'Facebook Ads',
    microsoft: 'Microsoft Ads',
    linkedin: 'LinkedIn Ads',
};

function StatCard({ label, value, sub }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <p className="text-sm text-gray-500">{label}</p>
            <p className="text-2xl font-bold text-gray-900 mt-1">{value}</p>
            {sub && <p className="text-xs text-gray-400 mt-1">{sub}</p>}
        </div>
    );
}

function PlatformRow({ name, data }) {
    return (
        <tr className="border-b border-gray-100">
            <td className="py-3 px-4 font-medium text-gray-900">{platformLabels[name] || name}</td>
            <td className="py-3 px-4 text-right">{data.impressions?.toLocaleString()}</td>
            <td className="py-3 px-4 text-right">{data.clicks?.toLocaleString()}</td>
            <td className="py-3 px-4 text-right">${data.cost?.toLocaleString()}</td>
            <td className="py-3 px-4 text-right">{data.conversions?.toLocaleString()}</td>
            <td className="py-3 px-4 text-right">${data.revenue?.toLocaleString()}</td>
            <td className="py-3 px-4 text-right font-semibold">
                <span className={data.roas >= 2 ? 'text-green-600' : data.roas >= 1 ? 'text-yellow-600' : 'text-red-600'}>
                    {data.roas}x
                </span>
            </td>
        </tr>
    );
}

function AgentResultCard({ agentType, activities, campaigns }) {
    const [expanded, setExpanded] = useState(false);
    const prettyName = agentType.replace(/([A-Z])/g, ' $1').trim();

    const completedCount = activities.filter(a => a.status === 'completed').length;
    const errorCount = activities.filter(a => a.status === 'failed').length;

    return (
        <div className="border border-gray-200 rounded-lg overflow-hidden">
            <button
                onClick={() => setExpanded(!expanded)}
                className="w-full flex items-center justify-between p-4 hover:bg-gray-50 transition"
            >
                <div className="flex items-center gap-3">
                    <span className="font-medium text-gray-900">{prettyName}</span>
                    <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                        {completedCount} completed
                    </span>
                    {errorCount > 0 && (
                        <span className="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full">
                            {errorCount} errors
                        </span>
                    )}
                </div>
                <svg className={`w-5 h-5 text-gray-400 transition ${expanded ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {expanded && (
                <div className="border-t border-gray-200 divide-y divide-gray-100">
                    {activities.map((activity, i) => {
                        const campaign = campaigns.find(c => c.id === activity.campaign_id);
                        return (
                            <div key={i} className="p-4 text-sm">
                                <div className="flex items-center gap-2 mb-1">
                                    <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColors[activity.status] || 'bg-gray-100 text-gray-700'}`}>
                                        {activity.status}
                                    </span>
                                    {campaign && (
                                        <span className="text-gray-500">{campaign.name}</span>
                                    )}
                                </div>
                                <p className="text-gray-700">{activity.description}</p>
                                {activity.details && typeof activity.details === 'object' && (
                                    <details className="mt-2">
                                        <summary className="text-xs text-indigo-600 cursor-pointer hover:underline">
                                            View raw details
                                        </summary>
                                        <pre className="mt-1 bg-gray-50 rounded p-3 text-xs text-gray-600 overflow-x-auto max-h-64">
                                            {JSON.stringify(activity.details, null, 2)}
                                        </pre>
                                    </details>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default function SandboxResults({ customer, campaigns, agentResults, performanceSummary, simulationComplete }) {
    const [polling, setPolling] = useState(!simulationComplete);

    // Poll for results while simulation is running
    useEffect(() => {
        if (!polling) return;

        const interval = setInterval(() => {
            router.reload({ only: ['customer', 'agentResults', 'performanceSummary', 'simulationComplete'] });
        }, 5000);

        return () => clearInterval(interval);
    }, [polling]);

    useEffect(() => {
        if (simulationComplete) setPolling(false);
    }, [simulationComplete]);

    const totals = performanceSummary?.totals || {};

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-3">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">Sandbox Results</h2>
                    {!simulationComplete && (
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 bg-yellow-100 text-yellow-800 text-xs font-medium rounded-full animate-pulse">
                            <span className="w-2 h-2 bg-yellow-500 rounded-full" />
                            Agents running...
                        </span>
                    )}
                </div>
            }
        >
            <Head title="Sandbox Results" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                    {/* Summary Stats */}
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        <StatCard label="Total Spend" value={`$${totals.cost?.toLocaleString() || 0}`} />
                        <StatCard label="Revenue" value={`$${totals.revenue?.toLocaleString() || 0}`} />
                        <StatCard label="ROAS" value={`${totals.roas || 0}x`} />
                        <StatCard label="Conversions" value={totals.conversions?.toLocaleString() || 0} />
                        <StatCard label="Clicks" value={totals.clicks?.toLocaleString() || 0} />
                        <StatCard label="Impressions" value={totals.impressions?.toLocaleString() || 0} />
                    </div>

                    {/* Platform Breakdown */}
                    {performanceSummary?.platforms && Object.keys(performanceSummary.platforms).length > 0 && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                            <div className="p-6 border-b border-gray-200">
                                <h3 className="text-lg font-semibold text-gray-900">Platform Performance (30 days)</h3>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-gray-50 text-gray-500 text-xs uppercase">
                                        <tr>
                                            <th className="py-3 px-4 text-left">Platform</th>
                                            <th className="py-3 px-4 text-right">Impressions</th>
                                            <th className="py-3 px-4 text-right">Clicks</th>
                                            <th className="py-3 px-4 text-right">Cost</th>
                                            <th className="py-3 px-4 text-right">Conversions</th>
                                            <th className="py-3 px-4 text-right">Revenue</th>
                                            <th className="py-3 px-4 text-right">ROAS</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {Object.entries(performanceSummary.platforms).map(([name, data]) => (
                                            <PlatformRow key={name} name={name} data={data} />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Campaign Cards */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Sandbox Campaigns</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {campaigns.map((campaign) => (
                                <div key={campaign.id} className="border border-gray-200 rounded-lg p-4">
                                    <div className="flex items-center justify-between mb-2">
                                        <h4 className="font-medium text-gray-900 text-sm">{campaign.name}</h4>
                                        <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">
                                            {campaign.platform}
                                        </span>
                                    </div>
                                    <p className="text-xs text-gray-500">{campaign.reason}</p>
                                    <p className="text-xs text-gray-400 mt-1">${campaign.daily_budget}/day</p>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Agent Results */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Agent Analysis Results</h3>

                        {!simulationComplete && Object.keys(agentResults).length === 0 && (
                            <div className="text-center py-12 text-gray-500">
                                <div className="animate-spin w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full mx-auto mb-4" />
                                <p>Agents are analyzing your sandbox campaigns...</p>
                                <p className="text-sm mt-1">This typically takes 1-2 minutes.</p>
                            </div>
                        )}

                        <div className="space-y-3">
                            {Object.entries(agentResults).map(([agentType, activities]) => (
                                <AgentResultCard
                                    key={agentType}
                                    agentType={agentType}
                                    activities={activities}
                                    campaigns={campaigns}
                                />
                            ))}
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="flex justify-between items-center">
                        <a
                            href={route('sandbox.index')}
                            className="text-sm text-gray-500 hover:text-gray-700"
                        >
                            ← Back to Sandbox
                        </a>
                        <div className="flex gap-3">
                            <button
                                onClick={() => router.delete(route('sandbox.destroy', customer.id))}
                                className="px-4 py-2 text-sm border border-red-300 text-red-700 rounded-lg hover:bg-red-50 transition"
                            >
                                Delete Sandbox
                            </button>
                            <a
                                href={route('sandbox.launch')}
                                onClick={(e) => { e.preventDefault(); router.post(route('sandbox.launch')); }}
                                className="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition"
                            >
                                Regenerate Sandbox
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
