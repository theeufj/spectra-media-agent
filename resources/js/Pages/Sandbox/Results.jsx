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

const severityStyles = {
    critical: { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-800', badge: 'bg-red-100 text-red-700', icon: '🚨' },
    high: { bg: 'bg-orange-50', border: 'border-orange-200', text: 'text-orange-800', badge: 'bg-orange-100 text-orange-700', icon: '⚠️' },
    medium: { bg: 'bg-yellow-50', border: 'border-yellow-200', text: 'text-yellow-800', badge: 'bg-yellow-100 text-yellow-700', icon: '📋' },
    low: { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-800', badge: 'bg-blue-100 text-blue-700', icon: '💡' },
    info: { bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-700', badge: 'bg-gray-100 text-gray-600', icon: '✅' },
};

const agentIcons = {
    HealthCheckAgent: '🩺',
    CampaignAlertService: '🔔',
    CampaignOptimizationAgent: '⚡',
    BudgetIntelligenceAgent: '💰',
    SearchTermMiningAgent: '🔍',
    CreativeIntelligenceAgent: '🎨',
    SelfHealingAgent: '🔧',
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

function ScoreBadge({ score, label }) {
    const color = score >= 80 ? 'text-green-600 bg-green-50 border-green-200'
        : score >= 50 ? 'text-yellow-600 bg-yellow-50 border-yellow-200'
        : 'text-red-600 bg-red-50 border-red-200';
    return (
        <div className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full border text-sm font-semibold ${color}`}>
            {score}/100 {label && <span className="font-normal text-xs opacity-75">{label}</span>}
        </div>
    );
}

function SeverityBadge({ severity }) {
    const s = severityStyles[severity] || severityStyles.info;
    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${s.badge}`}>
            {s.icon} {severity}
        </span>
    );
}

function HealthCheckDetails({ details }) {
    if (!details) return null;
    return (
        <div className="space-y-3">
            <div className="flex items-center gap-4">
                <div className={`px-3 py-1.5 rounded-lg text-sm font-semibold ${
                    details.overall_health === 'healthy' ? 'bg-green-100 text-green-800' :
                    details.overall_health === 'warning' ? 'bg-yellow-100 text-yellow-800' :
                    'bg-red-100 text-red-800'
                }`}>
                    {details.overall_health === 'healthy' ? '✅' : details.overall_health === 'warning' ? '⚠️' : '🚨'} Overall: {details.overall_health}
                </div>
                <div className="flex gap-3 text-sm">
                    {details.healthy > 0 && <span className="text-green-600">{details.healthy} healthy</span>}
                    {details.warning > 0 && <span className="text-yellow-600">{details.warning} warning</span>}
                    {details.critical > 0 && <span className="text-red-600">{details.critical} critical</span>}
                </div>
            </div>
            {details.campaigns?.map((c, i) => (
                <div key={i} className={`flex items-center justify-between p-3 rounded-lg border ${
                    c.health === 'healthy' ? 'bg-green-50 border-green-200' :
                    c.health === 'warning' ? 'bg-yellow-50 border-yellow-200' :
                    'bg-red-50 border-red-200'
                }`}>
                    <div>
                        <span className="font-medium text-gray-900 text-sm">{c.campaign}</span>
                        <span className="text-gray-500 text-xs ml-2">{c.platform}</span>
                    </div>
                    <div className="flex items-center gap-4 text-xs">
                        <span>CPA <strong>${c.cpa}</strong></span>
                        <span>ROAS <strong>{c.roas}x</strong></span>
                        <span>Spend <strong>${c.spend}</strong></span>
                    </div>
                </div>
            ))}
            {details.recommendations?.length > 0 && (
                <div className="space-y-1.5 mt-2">
                    {details.recommendations.map((r, i) => (
                        <p key={i} className="text-sm text-gray-700">{r}</p>
                    ))}
                </div>
            )}
        </div>
    );
}

function AlertDetails({ details }) {
    if (!details || !Array.isArray(details)) return null;
    return (
        <div className="space-y-2">
            {details.map((alert, i) => {
                const s = severityStyles[alert.severity] || severityStyles.info;
                return (
                    <div key={i} className={`p-3 rounded-lg border ${s.bg} ${s.border}`}>
                        <div className="flex items-center gap-2 mb-1">
                            <SeverityBadge severity={alert.severity} />
                            <span className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                                {alert.type?.replace(/_/g, ' ')}
                            </span>
                        </div>
                        <p className={`text-sm ${s.text}`}>{alert.message}</p>
                    </div>
                );
            })}
        </div>
    );
}

function OptimizationDetails({ details }) {
    if (!details) return null;
    return (
        <div className="space-y-3">
            <div className="flex items-center gap-3">
                <ScoreBadge score={details.optimization_score} label="optimisation" />
                {details.metrics_analyzed && (
                    <div className="flex gap-3 text-xs text-gray-500">
                        {Object.entries(details.metrics_analyzed).map(([k, v]) => (
                            <span key={k}>{k.replace(/_/g, ' ')}: <strong className="text-gray-700">{v}</strong></span>
                        ))}
                    </div>
                )}
            </div>
            {details.recommendations?.map((rec, i) => (
                <div key={i} className={`p-3 rounded-lg border ${
                    rec.priority === 'high' ? 'bg-orange-50 border-orange-200' :
                    rec.priority === 'medium' ? 'bg-yellow-50 border-yellow-200' :
                    'bg-blue-50 border-blue-200'
                }`}>
                    <div className="flex items-center gap-2 mb-1">
                        <SeverityBadge severity={rec.priority} />
                        <span className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                            {rec.area?.replace(/_/g, ' ')}
                        </span>
                    </div>
                    <p className="text-sm text-gray-800">{rec.action}</p>
                    {rec.expected_impact && (
                        <p className="text-xs text-gray-500 mt-1">Expected: {rec.expected_impact}</p>
                    )}
                </div>
            ))}
        </div>
    );
}

function BudgetDetails({ details }) {
    if (!details) return null;
    const actionColors = {
        reduce_budget: 'bg-red-100 text-red-700',
        increase_budget: 'bg-green-100 text-green-700',
        reallocate_to_peaks: 'bg-yellow-100 text-yellow-700',
        maintain: 'bg-blue-100 text-blue-700',
    };
    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div className="bg-gray-50 rounded-lg p-3 text-center">
                    <p className="text-xs text-gray-500">Daily Budget</p>
                    <p className="font-semibold text-gray-900">${details.current_daily_budget}</p>
                </div>
                <div className="bg-gray-50 rounded-lg p-3 text-center">
                    <p className="text-xs text-gray-500">Actual Spend/Day</p>
                    <p className="font-semibold text-gray-900">${details.actual_daily_spend}</p>
                </div>
                <div className="bg-gray-50 rounded-lg p-3 text-center">
                    <p className="text-xs text-gray-500">Utilisation</p>
                    <p className="font-semibold text-gray-900">{details.budget_utilization}</p>
                </div>
                <div className="bg-gray-50 rounded-lg p-3 text-center">
                    <p className="text-xs text-gray-500">Multiplier</p>
                    <p className="font-semibold text-gray-900">×{details.multiplier_suggested}</p>
                </div>
            </div>
            <div className={`p-3 rounded-lg ${actionColors[details.recommended_action] || 'bg-gray-100 text-gray-700'}`}>
                <p className="text-sm font-medium mb-1">
                    {details.recommended_action?.replace(/_/g, ' ')}
                </p>
                <p className="text-sm opacity-90">{details.recommendation}</p>
            </div>
            {(details.peak_hours?.length > 0 || details.trough_hours?.length > 0) && (
                <div className="flex gap-4 text-xs">
                    {details.peak_hours?.length > 0 && (
                        <span className="text-green-700">
                            📈 Peak hours: {details.peak_hours.map(h => `${h}:00`).join(', ')}
                        </span>
                    )}
                    {details.trough_hours?.length > 0 && (
                        <span className="text-red-700">
                            📉 Low hours: {details.trough_hours.map(h => `${h}:00`).join(', ')}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

function SearchTermDetails({ details }) {
    if (!details) return null;
    const qsColor = details.average_quality_score >= 7 ? 'text-green-600' :
        details.average_quality_score >= 5 ? 'text-yellow-600' : 'text-red-600';
    return (
        <div className="space-y-3">
            <div className="flex items-center gap-4">
                <div className="bg-gray-50 rounded-lg px-4 py-2 text-center">
                    <p className="text-xs text-gray-500">Avg Quality Score</p>
                    <p className={`text-xl font-bold ${qsColor}`}>{details.average_quality_score}/10</p>
                </div>
                <div className="flex gap-4 text-sm">
                    <span className="text-gray-500">{details.terms_analyzed} terms</span>
                    <span className="text-red-600">{details.low_qs_count} low QS</span>
                    <span className="text-green-600">{details.high_qs_count} high QS</span>
                </div>
            </div>
            <p className="text-sm text-gray-700">{details.summary}</p>
            {details.negative_keyword_recommendations?.length > 0 && (
                <div>
                    <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Negative Keyword Candidates</h4>
                    <div className="space-y-1.5">
                        {details.negative_keyword_recommendations.map((kw, i) => (
                            <div key={i} className="flex items-center gap-2 p-2 bg-red-50 border border-red-200 rounded-lg text-sm">
                                <span className="text-red-500">✕</span>
                                <code className="font-mono text-red-800">{kw.keyword}</code>
                                <span className="text-xs text-gray-500">QS {kw.quality_score}</span>
                                <span className="text-xs text-gray-500 ml-auto">{kw.reason}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {details.expansion_recommendations?.length > 0 && (
                <div>
                    <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Expansion Candidates</h4>
                    <div className="space-y-1.5">
                        {details.expansion_recommendations.map((kw, i) => (
                            <div key={i} className="flex items-center gap-2 p-2 bg-green-50 border border-green-200 rounded-lg text-sm">
                                <span className="text-green-500">✓</span>
                                <code className="font-mono text-green-800">{kw.keyword}</code>
                                <span className="text-xs text-gray-500">QS {kw.quality_score}</span>
                                <span className="text-xs text-gray-500 ml-auto">{kw.reason}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

function CreativeDetails({ details }) {
    if (!details) return null;
    return (
        <div className="space-y-3">
            <div className="flex items-center gap-3">
                <ScoreBadge score={details.creative_health_score} label="creative" />
                {details.metrics_reviewed && (
                    <div className="flex gap-3 text-xs text-gray-500">
                        {Object.entries(details.metrics_reviewed).map(([k, v]) => (
                            <span key={k}>{k.replace(/_/g, ' ')}: <strong className="text-gray-700">{v}</strong></span>
                        ))}
                    </div>
                )}
            </div>
            {details.insights?.map((insight, i) => {
                const s = severityStyles[insight.severity] || severityStyles.info;
                return (
                    <div key={i} className={`p-3 rounded-lg border ${s.bg} ${s.border}`}>
                        <div className="flex items-center gap-2 mb-1">
                            <SeverityBadge severity={insight.severity} />
                            <span className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                                {insight.type?.replace(/_/g, ' ')}
                            </span>
                        </div>
                        <p className={`text-sm ${s.text} mb-1`}>{insight.finding}</p>
                        <p className="text-sm text-gray-600">💡 {insight.recommendation}</p>
                    </div>
                );
            })}
        </div>
    );
}

function SelfHealingDetails({ details }) {
    if (!details) return null;
    const statusColor = details.campaign_status === 'healthy' ? 'bg-green-100 text-green-700' :
        details.campaign_status === 'needs_attention' ? 'bg-yellow-100 text-yellow-700' :
        'bg-red-100 text-red-700';
    return (
        <div className="space-y-3">
            <div className="flex items-center gap-3">
                <span className={`px-3 py-1 rounded-full text-xs font-semibold ${statusColor}`}>
                    {details.campaign_status === 'healthy' ? '✅' : details.campaign_status === 'needs_attention' ? '⚠️' : '🚨'}
                    {' '}{details.campaign_status?.replace(/_/g, ' ')}
                </span>
                <span className="text-sm text-gray-500">{details.issues_detected} issue{details.issues_detected !== 1 ? 's' : ''} detected</span>
            </div>
            {details.actions?.map((action, i) => {
                if (action.issue === 'none') {
                    return (
                        <div key={i} className="p-3 rounded-lg border bg-green-50 border-green-200">
                            <p className="text-sm text-green-800">✅ {action.detected}</p>
                            <p className="text-xs text-green-600 mt-1">{action.action_taken}</p>
                        </div>
                    );
                }
                const s = severityStyles[action.severity] || severityStyles.medium;
                return (
                    <div key={i} className={`p-3 rounded-lg border ${s.bg} ${s.border}`}>
                        <div className="flex items-center gap-2 mb-1.5">
                            <SeverityBadge severity={action.severity} />
                            <span className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                                {action.issue?.replace(/_/g, ' ')}
                            </span>
                        </div>
                        <p className={`text-sm font-medium ${s.text} mb-1`}>{action.detected}</p>
                        <p className="text-sm text-gray-700">{action.action_taken}</p>
                        {action.would_auto_fix && (
                            <p className="text-xs text-indigo-600 mt-2 flex items-center gap-1">
                                <span className="inline-block w-4 h-4 bg-indigo-100 rounded-full text-center text-[10px] leading-4">🤖</span>
                                {action.would_auto_fix}
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function DetailRenderer({ agentType, details }) {
    if (!details || typeof details !== 'object') return null;

    // Error results
    if (details.error) {
        return (
            <div className="p-3 bg-red-50 border border-red-200 rounded-lg">
                <p className="text-sm text-red-700">{details.error}</p>
            </div>
        );
    }

    switch (agentType) {
        case 'HealthCheckAgent': return <HealthCheckDetails details={details} />;
        case 'CampaignAlertService': return <AlertDetails details={details} />;
        case 'CampaignOptimizationAgent': return <OptimizationDetails details={details} />;
        case 'BudgetIntelligenceAgent': return <BudgetDetails details={details} />;
        case 'SearchTermMiningAgent': return <SearchTermDetails details={details} />;
        case 'CreativeIntelligenceAgent': return <CreativeDetails details={details} />;
        case 'SelfHealingAgent': return <SelfHealingDetails details={details} />;
        default:
            return (
                <pre className="bg-gray-50 rounded p-3 text-xs text-gray-600 overflow-x-auto max-h-64">
                    {JSON.stringify(details, null, 2)}
                </pre>
            );
    }
}

function AgentResultCard({ agentType, activities, campaigns }) {
    const [expanded, setExpanded] = useState(true);
    const prettyName = agentType.replace(/([A-Z])/g, ' $1').replace(/Agent$|Service$/, '').trim();
    const icon = agentIcons[agentType] || '🤖';

    const completedCount = activities.filter(a => a.status === 'completed').length;
    const errorCount = activities.filter(a => a.status === 'failed').length;
    const totalCount = activities.length;

    const overallStatus = errorCount === totalCount ? 'error' :
        errorCount > 0 ? 'partial' : 'success';

    return (
        <div className={`border rounded-xl overflow-hidden ${
            overallStatus === 'error' ? 'border-red-200' :
            overallStatus === 'partial' ? 'border-yellow-200' :
            'border-gray-200'
        }`}>
            <button
                onClick={() => setExpanded(!expanded)}
                className={`w-full flex items-center justify-between p-4 transition ${
                    expanded ? 'bg-gray-50' : 'hover:bg-gray-50'
                }`}
            >
                <div className="flex items-center gap-3">
                    <span className="text-xl">{icon}</span>
                    <span className="font-semibold text-gray-900">{prettyName}</span>
                    {completedCount > 0 && (
                        <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                            {completedCount} completed
                        </span>
                    )}
                    {errorCount > 0 && (
                        <span className="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full">
                            {errorCount} {errorCount === 1 ? 'error' : 'errors'}
                        </span>
                    )}
                </div>
                <svg className={`w-5 h-5 text-gray-400 transition-transform ${expanded ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {expanded && (
                <div className="border-t border-gray-200 divide-y divide-gray-100">
                    {activities.map((activity, i) => {
                        const campaign = campaigns.find(c => c.id === activity.campaign_id);
                        return (
                            <div key={i} className="p-4">
                                <div className="flex items-center gap-2 mb-3">
                                    <span className={`w-2 h-2 rounded-full ${
                                        activity.status === 'completed' ? 'bg-green-500' :
                                        activity.status === 'failed' ? 'bg-red-500' :
                                        'bg-yellow-500'
                                    }`} />
                                    {campaign && (
                                        <span className="font-medium text-gray-900 text-sm">{campaign.name}</span>
                                    )}
                                </div>
                                <DetailRenderer agentType={agentType} details={activity.details} />
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
