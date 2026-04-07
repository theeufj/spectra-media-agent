import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, Link } from '@inertiajs/react';

function ScoreRing({ score, label }) {
    const color = score >= 70 ? 'text-green-600' : score >= 50 ? 'text-yellow-600' : 'text-red-600';
    const bg = score >= 70 ? 'bg-green-50' : score >= 50 ? 'bg-yellow-50' : 'bg-red-50';
    return (
        <div className={`flex flex-col items-center justify-center rounded-xl p-6 ${bg}`}>
            <span className={`text-5xl font-bold ${color}`}>{score ?? '—'}</span>
            <span className="text-xs text-gray-500 mt-2">{label || 'CRO Score'}</span>
        </div>
    );
}

function StatCard({ label, value, unit, good }) {
    const color = good === true ? 'text-green-600' : good === false ? 'text-red-600' : 'text-gray-900';
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <p className="text-xs text-gray-500">{label}</p>
            <p className={`text-xl font-bold mt-1 ${color}`}>{value}{unit && <span className="text-sm font-normal text-gray-400 ml-1">{unit}</span>}</p>
        </div>
    );
}

function CwvBadge({ status }) {
    if (!status) return <span className="text-xs text-gray-400">N/A</span>;
    const isGood = status === 'good';
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${isGood ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
            {isGood ? '✓ Good' : '⚠ Needs Work'}
        </span>
    );
}

function SeverityBadge({ severity }) {
    const colors = {
        critical: 'bg-red-100 text-red-700',
        high: 'bg-orange-100 text-orange-700',
        medium: 'bg-yellow-100 text-yellow-700',
        low: 'bg-gray-100 text-gray-600',
    };
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase ${colors[severity] || colors.medium}`}>
            {severity}
        </span>
    );
}

function PriorityBadge({ priority }) {
    const colors = {
        critical: 'bg-red-100 text-red-700 border-red-200',
        high: 'bg-orange-100 text-orange-700 border-orange-200',
        medium: 'bg-yellow-100 text-yellow-700 border-yellow-200',
        low: 'bg-gray-100 text-gray-600 border-gray-200',
    };
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase border ${colors[priority] || colors.medium}`}>
            {priority}
        </span>
    );
}

function Section({ title, children }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-6">
            <h3 className="text-sm font-semibold text-gray-900 mb-4">{title}</h3>
            {children}
        </div>
    );
}

export default function CroAudit({ audit }) {
    const cwv = audit.core_web_vitals || {};
    const issues = audit.issues || [];
    const recommendations = audit.recommendations || [];
    const ctaButtons = audit.cta_buttons || [];
    const keywords = audit.keywords_found || [];

    const issuesByCategory = issues.reduce((acc, issue) => {
        const cat = issue.category || 'other';
        if (!acc[cat]) acc[cat] = [];
        acc[cat].push(issue);
        return acc;
    }, {});

    const categoryIcons = { performance: '⚡', cta: '🎯', messaging: '💬', other: '📋' };
    const categoryLabels = { performance: 'Performance', cta: 'Call-to-Action', messaging: 'Messaging', other: 'Other' };

    const handleRerun = () => {
        router.post(route('seo.cro.run'), { url: audit.url }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`CRO Audit — ${audit.url}`} />
            <div className="py-8">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <Link href={route('seo.cro')} className="text-xs text-gray-400 hover:text-gray-600 transition">← Back to Audits</Link>
                            <h1 className="text-xl font-bold text-gray-900 mt-1 break-all">{audit.url}</h1>
                            <p className="text-xs text-gray-400 mt-1">
                                Audited {audit.audited_at ? new Date(audit.audited_at).toLocaleString() : new Date(audit.created_at).toLocaleString()}
                                {audit.overall_score >= 70 ? (
                                    <span className="ml-2 text-green-600 font-medium">● Passed</span>
                                ) : (
                                    <span className="ml-2 text-red-600 font-medium">● Needs Improvement</span>
                                )}
                            </p>
                        </div>
                        <button
                            onClick={handleRerun}
                            className="px-4 py-2 bg-flame-orange-600 text-white rounded-lg text-sm font-medium hover:bg-flame-orange-700 transition"
                        >
                            Re-run Audit
                        </button>
                    </div>

                    {/* Score + Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <ScoreRing score={audit.overall_score} />
                        <StatCard label="Load Time" value={audit.load_time_ms ?? '—'} unit="ms" good={audit.load_time_ms ? audit.load_time_ms < 3000 : null} />
                        <StatCard label="Page Size" value={audit.page_size_kb ?? '—'} unit="KB" good={audit.page_size_kb ? audit.page_size_kb < 1000 : null} />
                        <StatCard label="DOM Elements" value={audit.dom_elements ?? '—'} good={audit.dom_elements ? audit.dom_elements < 1500 : null} />
                    </div>

                    {/* Core Web Vitals */}
                    <Section title="Core Web Vitals">
                        <div className="grid grid-cols-3 gap-4">
                            <div className="text-center p-4 rounded-lg bg-gray-50">
                                <p className="text-xs text-gray-500 mb-2">Largest Contentful Paint</p>
                                <CwvBadge status={cwv.lcp} />
                            </div>
                            <div className="text-center p-4 rounded-lg bg-gray-50">
                                <p className="text-xs text-gray-500 mb-2">First Input Delay</p>
                                <CwvBadge status={cwv.fid} />
                            </div>
                            <div className="text-center p-4 rounded-lg bg-gray-50">
                                <p className="text-xs text-gray-500 mb-2">Cumulative Layout Shift</p>
                                <CwvBadge status={cwv.cls} />
                            </div>
                        </div>
                    </Section>

                    {/* CTA Analysis + Message Match side by side */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <Section title="CTA Analysis">
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <span className="text-xs text-gray-500">CTAs Found</span>
                                    <span className="text-sm font-semibold text-gray-900">{audit.cta_count ?? 0}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-xs text-gray-500">Above-the-Fold CTA</span>
                                    {audit.has_above_fold_cta ? (
                                        <span className="text-xs font-medium text-green-600">✓ Yes</span>
                                    ) : (
                                        <span className="text-xs font-medium text-red-600">✗ No</span>
                                    )}
                                </div>
                                {audit.primary_cta && (
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs text-gray-500">Primary CTA</span>
                                        <span className="text-xs font-medium text-gray-900 bg-gray-100 px-2 py-0.5 rounded">"{audit.primary_cta}"</span>
                                    </div>
                                )}
                                {ctaButtons.length > 0 && (
                                    <div className="pt-3 border-t border-gray-100">
                                        <p className="text-xs text-gray-500 mb-2">Detected Buttons</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {ctaButtons.map((btn, i) => (
                                                <span key={i} className="text-[10px] bg-flame-orange-50 text-flame-orange-700 px-2 py-0.5 rounded-full border border-flame-orange-100">
                                                    {btn.text || btn}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </Section>

                        <Section title="Message Match">
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <span className="text-xs text-gray-500">Message Score</span>
                                    <span className={`text-lg font-bold ${audit.message_match_score >= 60 ? 'text-green-600' : 'text-red-600'}`}>
                                        {audit.message_match_score ?? '—'}/100
                                    </span>
                                </div>
                                {audit.message_analysis && (
                                    <p className="text-xs text-gray-600 leading-relaxed bg-gray-50 p-3 rounded">{audit.message_analysis}</p>
                                )}
                                {keywords.length > 0 && (
                                    <div className="pt-3 border-t border-gray-100">
                                        <p className="text-xs text-gray-500 mb-2">Keywords Found</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {keywords.map((kw, i) => (
                                                <span key={i} className="text-[10px] bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full border border-blue-100">{kw}</span>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </Section>
                    </div>

                    {/* Issues */}
                    {issues.length > 0 && (
                        <div className="mt-4">
                            <Section title={`Issues Found (${issues.length})`}>
                                <div className="space-y-6">
                                    {Object.entries(issuesByCategory).map(([category, catIssues]) => (
                                        <div key={category}>
                                            <h4 className="text-xs font-semibold text-gray-700 mb-3 flex items-center gap-2">
                                                <span>{categoryIcons[category] || '📋'}</span>
                                                {categoryLabels[category] || category}
                                                <span className="text-gray-400 font-normal">({catIssues.length})</span>
                                            </h4>
                                            <div className="space-y-2">
                                                {catIssues.map((issue, i) => (
                                                    <div key={i} className="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                                                        <SeverityBadge severity={issue.severity} />
                                                        <div>
                                                            <p className="text-sm font-medium text-gray-900">{issue.title}</p>
                                                            <p className="text-xs text-gray-500 mt-0.5">{issue.description}</p>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </Section>
                        </div>
                    )}

                    {/* Recommendations */}
                    {recommendations.length > 0 && (
                        <div className="mt-4 mb-8">
                            <Section title={`Recommendations (${recommendations.length})`}>
                                <div className="space-y-3">
                                    {recommendations.map((rec, i) => (
                                        <div key={i} className="p-4 border border-gray-100 rounded-lg">
                                            <div className="flex items-center gap-2 mb-1">
                                                <PriorityBadge priority={rec.priority} />
                                                <h4 className="text-sm font-semibold text-gray-900">{rec.title}</h4>
                                            </div>
                                            <p className="text-xs text-gray-600 leading-relaxed mt-1">{rec.action}</p>
                                        </div>
                                    ))}
                                </div>
                            </Section>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
