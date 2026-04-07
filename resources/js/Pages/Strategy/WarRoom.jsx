import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/* ─── Icons ─── */
const HeartIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>
);
const BoltIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
);
const ChartIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
);
const BellIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
);
const LockIcon = () => (
    <svg className="w-6 h-6 text-flame-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
);

const AGENT_ICONS = {
    optimization: '⚡',
    budget: '💰',
    creative: '🎨',
    maintenance: '🔧',
    monitoring: '📡',
    deployment: '🚀',
    health: '❤️',
};

const HEALTH_COLORS = {
    healthy: 'bg-green-100 text-green-800 border-green-200',
    warning: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    unhealthy: 'bg-red-100 text-red-800 border-red-200',
    critical: 'bg-red-200 text-red-900 border-red-300',
    unknown: 'bg-gray-100 text-gray-600 border-gray-200',
};

/* ─── Upgrade Prompt ─── */
function UpgradePrompt() {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-12 text-center max-w-lg mx-auto mt-12">
            <div className="mx-auto w-14 h-14 bg-flame-orange-100 rounded-full flex items-center justify-center mb-5">
                <LockIcon />
            </div>
            <h3 className="text-xl font-semibold text-gray-900 mb-2">Strategy War Room</h3>
            <p className="text-sm text-gray-500 mb-6 max-w-md mx-auto">
                Your command center for live agent activity, campaign health monitoring,
                AI optimization recommendations, and cross-platform performance — all in one view.
            </p>
            <Link
                href={route('subscription.pricing')}
                className="inline-flex items-center px-6 py-2.5 bg-flame-orange-600 text-white text-sm font-medium rounded-lg hover:bg-flame-orange-700 transition"
            >
                Upgrade to Growth Plan
            </Link>
            <p className="text-xs text-gray-400 mt-3">Available on Growth ($249/mo) and Agency plans</p>
        </div>
    );
}

/* ─── Health Status Panel ─── */
function HealthPanel({ health }) {
    const status = health?.overall_health || 'unknown';
    const issues = health?.issues || [];
    const warnings = health?.warnings || [];

    return (
        <div className={`rounded-lg border p-4 ${HEALTH_COLORS[status] || HEALTH_COLORS.unknown}`}>
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <HeartIcon />
                    <div>
                        <h3 className="font-semibold text-sm">Campaign Health</h3>
                        <p className="text-xs capitalize">{status}</p>
                    </div>
                </div>
                <div className="flex items-center gap-4 text-xs">
                    {issues.length > 0 && (
                        <span className="flex items-center gap-1">
                            <span className="w-2 h-2 bg-red-500 rounded-full" />
                            {issues.length} issue{issues.length !== 1 && 's'}
                        </span>
                    )}
                    {warnings.length > 0 && (
                        <span className="flex items-center gap-1">
                            <span className="w-2 h-2 bg-yellow-500 rounded-full" />
                            {warnings.length} warning{warnings.length !== 1 && 's'}
                        </span>
                    )}
                    {issues.length === 0 && warnings.length === 0 && (
                        <span className="text-green-700">All systems operational</span>
                    )}
                </div>
            </div>
            {issues.length > 0 && (
                <div className="mt-3 space-y-1">
                    {issues.slice(0, 3).map((issue, i) => (
                        <p key={i} className="text-xs bg-white/50 rounded px-2 py-1">{typeof issue === 'string' ? issue : issue.message || JSON.stringify(issue)}</p>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ─── Activity Feed ─── */
function ActivityFeed({ activities }) {
    const [expanded, setExpanded] = useState(null);

    if (activities.length === 0) {
        return (
            <div className="text-center py-8 text-gray-400 text-sm">
                No agent activity yet. Agents will appear here as they optimize your campaigns.
            </div>
        );
    }

    return (
        <div className="space-y-2 max-h-[480px] overflow-y-auto pr-1">
            {activities.map((a) => (
                <div
                    key={a.id}
                    className="bg-white rounded-lg border border-gray-100 p-3 hover:border-gray-200 transition cursor-pointer"
                    onClick={() => setExpanded(expanded === a.id ? null : a.id)}
                >
                    <div className="flex items-start gap-2">
                        <span className="text-base mt-0.5">{AGENT_ICONS[a.agent_type] || '🤖'}</span>
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-xs font-medium text-gray-900 truncate">{a.action}</span>
                                <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-medium whitespace-nowrap ${
                                    a.status === 'completed' ? 'bg-green-50 text-green-700' :
                                    a.status === 'failed' ? 'bg-red-50 text-red-700' :
                                    'bg-blue-50 text-blue-700'
                                }`}>
                                    {a.status}
                                </span>
                            </div>
                            <p className="text-[11px] text-gray-500 mt-0.5 line-clamp-2">{a.description}</p>
                            <span className="text-[10px] text-gray-400 mt-1 block">
                                {new Date(a.created_at).toLocaleString()}
                            </span>
                        </div>
                    </div>
                    {expanded === a.id && a.details && (
                        <pre className="mt-2 text-[10px] bg-gray-50 rounded p-2 overflow-x-auto text-gray-600 max-h-32 overflow-y-auto">
                            {JSON.stringify(a.details, null, 2)}
                        </pre>
                    )}
                </div>
            ))}
        </div>
    );
}

/* ─── Optimization Queue ─── */
function OptimizationQueue({ recommendations }) {
    const handleAction = (id, action) => {
        router.post(route(`strategy.war-room.recommendations.${action}`, id), {}, {
            preserveScroll: true,
        });
    };

    if (recommendations.length === 0) {
        return (
            <div className="text-center py-8 text-gray-400 text-sm">
                No pending recommendations. The optimization agent will surface suggestions here.
            </div>
        );
    }

    return (
        <div className="space-y-2 max-h-[320px] overflow-y-auto pr-1">
            {recommendations.map((r) => (
                <div key={r.id} className="bg-white rounded-lg border border-gray-100 p-3">
                    <div className="flex items-start justify-between gap-2">
                        <div className="flex-1 min-w-0">
                            <span className="text-xs font-medium text-gray-900 capitalize">{r.type?.replace(/_/g, ' ')}</span>
                            <p className="text-[11px] text-gray-500 mt-0.5 line-clamp-3">{r.rationale}</p>
                        </div>
                    </div>
                    {r.requires_approval && (
                        <div className="flex items-center gap-2 mt-2">
                            <button
                                onClick={() => handleAction(r.id, 'approve')}
                                className="text-[11px] px-2.5 py-1 bg-green-50 text-green-700 rounded-md hover:bg-green-100 font-medium transition"
                            >
                                Approve
                            </button>
                            <button
                                onClick={() => handleAction(r.id, 'reject')}
                                className="text-[11px] px-2.5 py-1 bg-gray-50 text-gray-500 rounded-md hover:bg-gray-100 font-medium transition"
                            >
                                Dismiss
                            </button>
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

/* ─── Performance Snapshot ─── */
function PerformanceSnapshot({ performance }) {
    if (!performance?.totals) {
        return (
            <div className="text-center py-8 text-gray-400 text-sm">
                No performance data yet. Metrics will appear once your campaigns are running.
            </div>
        );
    }

    const t = performance.totals;
    const metrics = [
        { label: 'Impressions', value: (t.impressions || 0).toLocaleString() },
        { label: 'Clicks', value: (t.clicks || 0).toLocaleString() },
        { label: 'Spend', value: `$${(t.cost || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` },
        { label: 'Conversions', value: (t.conversions || 0).toLocaleString(undefined, { maximumFractionDigits: 1 }) },
        { label: 'CTR', value: `${t.ctr || 0}%` },
        { label: 'ROAS', value: `${t.roas || 0}x` },
    ];

    return (
        <div>
            <div className="grid grid-cols-3 gap-3">
                {metrics.map((m) => (
                    <div key={m.label} className="bg-white rounded-lg border border-gray-100 p-3 text-center">
                        <p className="text-[10px] text-gray-400 uppercase tracking-wider">{m.label}</p>
                        <p className="text-lg font-semibold text-gray-900 mt-0.5">{m.value}</p>
                    </div>
                ))}
            </div>
            {performance.daily?.length > 0 && (
                <div className="mt-4 bg-white rounded-lg border border-gray-100 p-3">
                    <p className="text-[10px] text-gray-400 uppercase tracking-wider mb-2">Daily Spend (7 days)</p>
                    <div className="flex items-end gap-1 h-20">
                        {performance.daily.map((d) => {
                            const maxCost = Math.max(...performance.daily.map(dd => dd.cost || 0), 1);
                            const pct = ((d.cost || 0) / maxCost) * 100;
                            return (
                                <div key={d.date} className="flex-1 flex flex-col items-center gap-1">
                                    <div
                                        className="w-full bg-flame-orange-200 rounded-t hover:bg-flame-orange-400 transition"
                                        style={{ height: `${Math.max(pct, 4)}%` }}
                                        title={`${d.date}: $${(d.cost || 0).toFixed(2)}`}
                                    />
                                    <span className="text-[8px] text-gray-400">{d.date.slice(5)}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

/* ─── Alerts Bar ─── */
function AlertsBar({ alerts }) {
    if (alerts.length === 0) return null;

    return (
        <div className="space-y-2">
            {alerts.map((a) => (
                <div key={a.id} className="flex items-center justify-between bg-white rounded-lg border border-gray-100 px-4 py-2.5">
                    <div className="flex items-center gap-3 min-w-0">
                        <BellIcon />
                        <div className="min-w-0">
                            <p className="text-xs font-medium text-gray-900 truncate">{a.title}</p>
                            <p className="text-[11px] text-gray-500 truncate">{a.message}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 ml-3 flex-shrink-0">
                        {a.action_url && (
                            <Link href={a.action_url} className="text-[11px] text-flame-orange-600 hover:underline font-medium">
                                View
                            </Link>
                        )}
                        <span className="text-[10px] text-gray-400 whitespace-nowrap">
                            {new Date(a.created_at).toLocaleDateString()}
                        </span>
                    </div>
                </div>
            ))}
        </div>
    );
}

/* ─── Main Page ─── */
export default function WarRoom({
    canAccessWarRoom = false,
    health,
    activities = [],
    recommendations = [],
    performance,
    alerts = [],
    abTests = [],
}) {
    return (
        <AuthenticatedLayout>
            <Head title="War Room" />
            <div className="py-6">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Strategy War Room</h1>
                            <p className="text-sm text-gray-500 mt-0.5">Live agent activity, health monitoring &amp; optimization command center</p>
                        </div>
                        {canAccessWarRoom && abTests.length > 0 && (
                            <div className="flex items-center gap-2">
                                <span className="text-xs text-gray-500">{abTests.length} A/B test{abTests.length !== 1 && 's'} running</span>
                                <span className="w-2 h-2 bg-blue-500 rounded-full animate-pulse" />
                            </div>
                        )}
                    </div>

                    {!canAccessWarRoom ? (
                        <UpgradePrompt />
                    ) : (
                        <div className="space-y-6">
                            {/* Health Status — full width */}
                            <HealthPanel health={health} />

                            {/* Two column grid: Activity + Recommendations/Performance */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                {/* Left: Agent Activity Feed */}
                                <div>
                                    <div className="flex items-center gap-2 mb-3">
                                        <BoltIcon />
                                        <h2 className="text-sm font-semibold text-gray-900">Agent Activity</h2>
                                        <span className="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{activities.length}</span>
                                    </div>
                                    <ActivityFeed activities={activities} />
                                </div>

                                {/* Right: Optimization Queue + Performance */}
                                <div className="space-y-6">
                                    <div>
                                        <div className="flex items-center gap-2 mb-3">
                                            <BoltIcon />
                                            <h2 className="text-sm font-semibold text-gray-900">Optimization Queue</h2>
                                            <span className="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{recommendations.length}</span>
                                        </div>
                                        <OptimizationQueue recommendations={recommendations} />
                                    </div>

                                    <div>
                                        <div className="flex items-center gap-2 mb-3">
                                            <ChartIcon />
                                            <h2 className="text-sm font-semibold text-gray-900">Performance (7 days)</h2>
                                        </div>
                                        <PerformanceSnapshot performance={performance} />
                                    </div>
                                </div>
                            </div>

                            {/* Alerts bar — bottom */}
                            {alerts.length > 0 && (
                                <div>
                                    <div className="flex items-center gap-2 mb-3">
                                        <BellIcon />
                                        <h2 className="text-sm font-semibold text-gray-900">Alerts</h2>
                                        <span className="text-[10px] bg-red-50 text-red-600 px-1.5 py-0.5 rounded-full">{alerts.length} unread</span>
                                    </div>
                                    <AlertsBar alerts={alerts} />
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
