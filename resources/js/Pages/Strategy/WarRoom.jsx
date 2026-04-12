import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
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

/* ─── Competitive Intel Panel ─── */
function CompetitiveIntelPanel({ strategy, updatedAt }) {
    if (!strategy) {
        return (
            <div className="text-center py-6 text-gray-400 text-sm">
                No competitive intelligence yet. The agent runs weekly to analyze your competitors.
                <Link href={route('seo.competitors')} className="block mt-2 text-flame-orange-600 hover:underline text-xs">
                    View Competitor Analysis →
                </Link>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {updatedAt && (
                <p className="text-[10px] text-gray-400 text-right">Updated {new Date(updatedAt).toLocaleDateString()}</p>
            )}

            {/* Positioning */}
            {strategy.positioning_strategy?.primary_angle && (
                <div className="bg-white rounded-lg border border-gray-100 p-3">
                    <p className="text-[10px] text-gray-400 uppercase tracking-wider mb-1">Positioning</p>
                    <p className="text-xs text-gray-800 font-medium">{strategy.positioning_strategy.primary_angle}</p>
                </div>
            )}

            {/* Quick Wins */}
            {Array.isArray(strategy.quick_wins) && strategy.quick_wins.length > 0 && (
                <div className="bg-white rounded-lg border border-gray-100 p-3">
                    <p className="text-[10px] text-gray-400 uppercase tracking-wider mb-1">Quick Wins</p>
                    <ul className="space-y-1">
                        {strategy.quick_wins.slice(0, 4).map((w, i) => (
                            <li key={i} className="flex items-start gap-1.5 text-xs text-gray-600">
                                <span className="text-green-500 mt-0.5 flex-shrink-0">✓</span>
                                <span>{typeof w === 'string' ? w : w.action || JSON.stringify(w)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Attack Keywords */}
            {Array.isArray(strategy.keyword_strategy?.attack_keywords) && strategy.keyword_strategy.attack_keywords.length > 0 && (
                <div className="bg-white rounded-lg border border-gray-100 p-3">
                    <p className="text-[10px] text-gray-400 uppercase tracking-wider mb-1">Attack Keywords</p>
                    <div className="flex flex-wrap gap-1">
                        {strategy.keyword_strategy.attack_keywords.slice(0, 6).map((kw, i) => (
                            <span key={i} className="text-xs px-2 py-0.5 bg-red-50 text-red-700 rounded">{kw}</span>
                        ))}
                    </div>
                </div>
            )}

            <Link href={route('seo.competitors')} className="block text-center text-xs text-flame-orange-600 hover:underline pt-1">
                View Full Strategy →
            </Link>
        </div>
    );
}

/* ─── Competitor Pin Panel (3 URL slots) ─── */
function CompetitorPinPanel({ competitors }) {
    const { data, setData, post, processing, errors, reset } = useForm({ url: '' });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('strategy.war-room.competitors.add'), {
            preserveScroll: true,
            onSuccess: () => reset('url'),
        });
    };

    const handleRemove = (id) => {
        router.delete(route('strategy.war-room.competitors.remove', id), {
            preserveScroll: true,
        });
    };

    return (
        <div className="space-y-3">
            {/* Input */}
            {competitors.length < 3 && (
                <form onSubmit={handleSubmit} className="flex gap-2">
                    <input
                        type="url"
                        value={data.url}
                        onChange={(e) => setData('url', e.target.value)}
                        placeholder="https://competitor.com"
                        className="flex-1 text-xs rounded-lg border border-gray-200 px-3 py-2 focus:border-flame-orange-400 focus:ring-flame-orange-400"
                    />
                    <button
                        type="submit"
                        disabled={processing || !data.url}
                        className="text-xs px-4 py-2 bg-flame-orange-600 text-white rounded-lg hover:bg-flame-orange-700 disabled:opacity-50 font-medium transition"
                    >
                        {processing ? 'Adding...' : 'Add'}
                    </button>
                </form>
            )}
            {errors.url && <p className="text-xs text-red-500">{errors.url}</p>}
            <p className="text-[10px] text-gray-400">{competitors.length}/3 competitor slots used</p>

            {/* Competitor cards */}
            {competitors.length === 0 ? (
                <div className="text-center py-6 text-gray-400 text-sm">
                    Pin up to 3 competitor URLs to track. We'll scrape, analyze, and compare them against your business.
                </div>
            ) : (
                <div className="space-y-2">
                    {competitors.map((c) => (
                        <div key={c.id} className="bg-white rounded-lg border border-gray-100 p-3">
                            <div className="flex items-center justify-between">
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-medium text-gray-900 truncate">{c.domain}</p>
                                    <p className="text-[10px] text-gray-400 truncate">{c.url}</p>
                                </div>
                                <div className="flex items-center gap-2 ml-2 flex-shrink-0">
                                    {c.is_analyzing ? (
                                        <span className="flex items-center gap-1 text-[10px] text-blue-600">
                                            <span className="w-1.5 h-1.5 bg-blue-500 rounded-full animate-pulse" />
                                            Analyzing...
                                        </span>
                                    ) : (
                                        <span className="text-[10px] text-green-600">✓ Analyzed</span>
                                    )}
                                    <button
                                        onClick={() => handleRemove(c.id)}
                                        className="text-gray-400 hover:text-red-500 transition p-0.5"
                                        title="Remove competitor"
                                    >
                                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            </div>

                            {/* Analysis summary */}
                            {!c.is_analyzing && c.value_propositions && (
                                <div className="mt-2 pt-2 border-t border-gray-50">
                                    {Array.isArray(c.value_propositions) && c.value_propositions.length > 0 && (
                                        <div className="mb-1.5">
                                            <p className="text-[10px] text-gray-400 uppercase tracking-wider mb-0.5">Value Props</p>
                                            <div className="flex flex-wrap gap-1">
                                                {c.value_propositions.slice(0, 3).map((vp, i) => (
                                                    <span key={i} className="text-[10px] px-1.5 py-0.5 bg-purple-50 text-purple-700 rounded">{typeof vp === 'string' ? vp : vp.proposition || JSON.stringify(vp)}</span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    {c.impression_share != null && (
                                        <p className="text-[10px] text-gray-500">Impression share: <span className="font-medium text-gray-700">{(c.impression_share * 100).toFixed(1)}%</span></p>
                                    )}
                                    {c.last_analyzed_at && (
                                        <p className="text-[10px] text-gray-400 mt-0.5">Last analyzed: {new Date(c.last_analyzed_at).toLocaleDateString()}</p>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ─── Gap Analysis Dashboard ─── */
function GapDashboard({ gapAnalysis, gapAnalysisAt }) {
    const [activeTab, setActiveTab] = useState('keywords');

    if (!gapAnalysis) {
        return (
            <div className="text-center py-6 text-gray-400 text-sm">
                Gap analysis will generate automatically once competitor analysis is complete.
            </div>
        );
    }

    const tabs = [
        { key: 'keywords', label: 'Keyword Gaps' },
        { key: 'messaging', label: 'Messaging Gaps' },
        { key: 'strengths', label: 'Our Strengths' },
        { key: 'counters', label: 'Counter Strategies' },
    ];

    return (
        <div className="space-y-3">
            {/* Summary */}
            {gapAnalysis.summary && (
                <div className="bg-gradient-to-r from-flame-orange-50 to-orange-50 rounded-lg p-3 border border-flame-orange-100">
                    <p className="text-xs text-gray-700">{gapAnalysis.summary}</p>
                </div>
            )}

            {/* Quick wins */}
            {Array.isArray(gapAnalysis.quick_wins) && gapAnalysis.quick_wins.length > 0 && (
                <div className="bg-white rounded-lg border border-green-100 p-3">
                    <p className="text-[10px] text-green-600 uppercase tracking-wider font-medium mb-1.5">Quick Wins</p>
                    <ul className="space-y-1">
                        {gapAnalysis.quick_wins.map((w, i) => (
                            <li key={i} className="flex items-start gap-1.5 text-xs text-gray-600">
                                <span className="text-green-500 mt-0.5 flex-shrink-0">✓</span>
                                <span>{w}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Tab navigation */}
            <div className="flex gap-1 border-b border-gray-100 pb-1">
                {tabs.map((tab) => (
                    <button
                        key={tab.key}
                        onClick={() => setActiveTab(tab.key)}
                        className={`text-[11px] px-2.5 py-1.5 rounded-t font-medium transition ${
                            activeTab === tab.key
                                ? 'bg-white text-flame-orange-600 border border-b-0 border-gray-200'
                                : 'text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {/* Tab content */}
            <div className="min-h-[120px]">
                {activeTab === 'keywords' && (
                    <KeywordGapsTab gaps={gapAnalysis.keyword_gaps || []} />
                )}
                {activeTab === 'messaging' && (
                    <MessagingGapsTab gaps={gapAnalysis.messaging_gaps || []} />
                )}
                {activeTab === 'strengths' && (
                    <StrengthsTab strengths={gapAnalysis.strengths_to_exploit || []} />
                )}
                {activeTab === 'counters' && (
                    <CounterStrategiesTab strategies={gapAnalysis.counter_strategies || []} />
                )}
            </div>

            {gapAnalysisAt && (
                <p className="text-[10px] text-gray-400 text-right">Analysis generated {new Date(gapAnalysisAt).toLocaleDateString()}</p>
            )}
        </div>
    );
}

function KeywordGapsTab({ gaps }) {
    if (gaps.length === 0) return <p className="text-xs text-gray-400 py-4 text-center">No keyword gaps identified yet.</p>;
    const priorityColor = { high: 'bg-red-50 text-red-700', medium: 'bg-yellow-50 text-yellow-700', low: 'bg-gray-50 text-gray-600' };

    return (
        <div className="space-y-1.5">
            {gaps.slice(0, 8).map((g, i) => (
                <div key={i} className="flex items-center justify-between bg-white rounded border border-gray-100 px-3 py-2">
                    <div className="min-w-0 flex-1">
                        <span className="text-xs font-medium text-gray-900">{g.keyword}</span>
                        <span className="text-[10px] text-gray-400 ml-2">{g.competitor}</span>
                    </div>
                    <div className="flex items-center gap-2 ml-2">
                        <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${priorityColor[g.opportunity] || priorityColor.low}`}>
                            {g.opportunity}
                        </span>
                    </div>
                </div>
            ))}
        </div>
    );
}

function MessagingGapsTab({ gaps }) {
    if (gaps.length === 0) return <p className="text-xs text-gray-400 py-4 text-center">No messaging gaps identified yet.</p>;

    return (
        <div className="space-y-2">
            {gaps.slice(0, 5).map((g, i) => (
                <div key={i} className="bg-white rounded-lg border border-gray-100 p-3">
                    <p className="text-xs font-medium text-gray-900">{g.gap}</p>
                    {g.competitors_using?.length > 0 && (
                        <p className="text-[10px] text-gray-400 mt-0.5">Used by: {g.competitors_using.join(', ')}</p>
                    )}
                    <p className="text-xs text-flame-orange-700 mt-1">{g.recommended_angle}</p>
                </div>
            ))}
        </div>
    );
}

function StrengthsTab({ strengths }) {
    if (strengths.length === 0) return <p className="text-xs text-gray-400 py-4 text-center">No exploitable strengths identified yet.</p>;

    return (
        <div className="space-y-2">
            {strengths.slice(0, 5).map((s, i) => (
                <div key={i} className="bg-white rounded-lg border border-green-100 p-3">
                    <p className="text-xs font-medium text-green-800">{s.strength}</p>
                    {s.competitors_weak_on?.length > 0 && (
                        <p className="text-[10px] text-gray-400 mt-0.5">Competitors weak: {s.competitors_weak_on.join(', ')}</p>
                    )}
                    <p className="text-xs text-gray-600 mt-1 italic">"{s.ad_copy_angle}"</p>
                </div>
            ))}
        </div>
    );
}

function CounterStrategiesTab({ strategies }) {
    if (strategies.length === 0) return <p className="text-xs text-gray-400 py-4 text-center">No counter strategies generated yet.</p>;

    return (
        <div className="space-y-2">
            {strategies.slice(0, 4).map((s, i) => (
                <div key={i} className="bg-white rounded-lg border border-gray-100 p-3">
                    <div className="flex items-center justify-between mb-1">
                        <span className="text-[10px] text-gray-400 uppercase tracking-wider">vs {s.competitor}</span>
                    </div>
                    <p className="text-xs text-gray-700 mb-1.5">{s.their_weakness}</p>
                    <div className="bg-gray-50 rounded p-2">
                        <p className="text-xs font-medium text-gray-900">{s.headline_example}</p>
                        <p className="text-[11px] text-gray-600 mt-0.5">{s.description_example}</p>
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
    competitiveStrategy = null,
    strategyUpdatedAt = null,
    warRoomCompetitors = [],
    gapAnalysis = null,
    gapAnalysisAt = null,
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

                                    <div>
                                        <div className="flex items-center gap-2 mb-3">
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                                            <h2 className="text-sm font-semibold text-gray-900">Competitive Intel</h2>
                                        </div>
                                        <CompetitiveIntelPanel strategy={competitiveStrategy} updatedAt={strategyUpdatedAt} />
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

                            {/* Competitor War Room — full width */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <div>
                                    <div className="flex items-center gap-2 mb-3">
                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                        <h2 className="text-sm font-semibold text-gray-900">Competitor Watch List</h2>
                                    </div>
                                    <CompetitorPinPanel competitors={warRoomCompetitors} />
                                </div>

                                <div>
                                    <div className="flex items-center gap-2 mb-3">
                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                                        <h2 className="text-sm font-semibold text-gray-900">Gap Analysis</h2>
                                    </div>
                                    <GapDashboard gapAnalysis={gapAnalysis} gapAnalysisAt={gapAnalysisAt} />
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
