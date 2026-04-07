import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

function Section({ title, children }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">{title}</h2>
            {children}
        </div>
    );
}

function CopyButton({ text }) {
    const [copied, setCopied] = useState(false);
    const handleCopy = () => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };
    return (
        <button onClick={handleCopy} className="ml-2 px-2 py-0.5 text-xs rounded border border-gray-300 text-gray-500 hover:bg-gray-100 shrink-0">
            {copied ? '✓ Copied' : 'Copy'}
        </button>
    );
}

function IssueList({ items, type = 'error' }) {
    if (!items || items.length === 0) return <p className="text-sm text-gray-500">No issues found.</p>;
    const colors = type === 'error' ? 'bg-red-50 text-red-700' : type === 'warning' ? 'bg-yellow-50 text-yellow-700' : 'bg-blue-50 text-blue-700';
    return (
        <div className="space-y-2">
            {items.map((item, i) => (
                <div key={i} className={`p-3 rounded-lg text-sm ${colors}`}>
                    {typeof item === 'string' ? item : item.message || item.title || JSON.stringify(item)}
                </div>
            ))}
        </div>
    );
}

function KeywordTag({ keyword, present }) {
    return (
        <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium mr-1.5 mb-1.5 ${present ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
            {keyword}
        </span>
    );
}

function PriorityBadge({ priority }) {
    const colors = {
        high: 'bg-red-100 text-red-700',
        medium: 'bg-yellow-100 text-yellow-700',
        low: 'bg-blue-100 text-blue-700',
    };
    return (
        <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${colors[priority] || colors.medium}`}>
            {priority}
        </span>
    );
}

function CategoryIcon({ category }) {
    const icons = {
        meta_tags: '🏷️',
        keywords: '🔑',
        content: '📝',
        technical: '⚙️',
        schema: '📊',
        social: '📱',
        performance: '⚡',
        backlinks: '🔗',
    };
    return <span className="text-base mr-2">{icons[category] || '💡'}</span>;
}

export default function Audit({ audit }) {
    if (!audit) {
        return (
            <AuthenticatedLayout>
                <Head title="SEO Audit" />
                <div className="py-8 text-center text-gray-500">Audit not found.</div>
            </AuthenticatedLayout>
        );
    }

    const scoreColor = audit.score >= 80 ? 'text-green-600' : audit.score >= 60 ? 'text-yellow-600' : 'text-red-600';

    return (
        <AuthenticatedLayout>
            <Head title={`SEO Audit - ${audit.url}`} />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <a href={route('seo.index')} className="text-sm text-flame-orange-600 hover:underline mb-1 inline-block">← Back to SEO</a>
                            <h1 className="text-2xl font-bold text-gray-900">{audit.url}</h1>
                            <p className="mt-1 text-sm text-gray-500">Audited on {new Date(audit.created_at).toLocaleString()}</p>
                        </div>
                        <div className="text-center">
                            <span className={`text-5xl font-bold ${scoreColor}`}>{audit.score}</span>
                            <p className="text-xs text-gray-500 mt-1">Overall Score</p>
                        </div>
                    </div>

                    {/* Issues */}
                    {audit.issues && audit.issues.length > 0 && (
                        <Section title="Issues">
                            <IssueList items={audit.issues} type="error" />
                        </Section>
                    )}

                    {/* AI Recommendations — Actionable */}
                    {audit.recommendations && audit.recommendations.length > 0 && (
                        <Section title="Actionable Recommendations">
                            <div className="space-y-4">
                                {audit.recommendations.map((rec, i) => (
                                    <div key={i} className="border border-gray-200 rounded-lg p-4">
                                        <div className="flex items-start justify-between mb-2">
                                            <div className="flex items-center">
                                                <CategoryIcon category={rec.category} />
                                                <span className="text-sm font-medium text-gray-900">{rec.message || (typeof rec === 'string' ? rec : JSON.stringify(rec))}</span>
                                            </div>
                                            {rec.priority && <PriorityBadge priority={rec.priority} />}
                                        </div>
                                        {rec.action && (
                                            <div className="mt-2 bg-gray-50 rounded-lg p-3 flex items-start justify-between gap-2">
                                                <code className="text-xs text-gray-700 break-all whitespace-pre-wrap flex-1">{rec.action}</code>
                                                <CopyButton text={rec.action} />
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </Section>
                    )}

                    {/* Keyword Analysis */}
                    {audit.content_analysis && (
                        <Section title="Keyword Analysis">
                            <div className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <p className="text-xs text-gray-500 mb-1">Word Count</p>
                                        <p className="text-xl font-bold text-gray-900">{audit.content_analysis.word_count ?? 0}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-gray-500 mb-1">Keywords Detected</p>
                                        <p className="text-xl font-bold text-gray-900">{audit.content_analysis.detected_keywords?.length ?? 0}</p>
                                    </div>
                                </div>

                                {audit.content_analysis.detected_keywords?.length > 0 && (
                                    <div>
                                        <p className="text-sm font-medium text-gray-700 mb-2">Top Keywords Found</p>
                                        <div className="flex flex-wrap">
                                            {audit.content_analysis.detected_keywords.map((kw, i) => (
                                                <KeywordTag key={i} keyword={kw} present={true} />
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {audit.content_analysis.keyword_phrases?.length > 0 && (
                                    <div>
                                        <p className="text-sm font-medium text-gray-700 mb-2">Key Phrases</p>
                                        <div className="flex flex-wrap">
                                            {audit.content_analysis.keyword_phrases.map((phrase, i) => (
                                                <KeywordTag key={i} keyword={phrase} present={true} />
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {audit.content_analysis.keywords_missing_from_title?.length > 0 && (
                                    <div className="bg-yellow-50 rounded-lg p-3">
                                        <p className="text-sm font-medium text-yellow-800 mb-1">⚠ Keywords Missing from Title Tag</p>
                                        <p className="text-xs text-yellow-700">Add these to your &lt;title&gt; tag for better rankings:</p>
                                        <div className="flex flex-wrap mt-2">
                                            {audit.content_analysis.keywords_missing_from_title.map((kw, i) => (
                                                <span key={i} className="inline-block px-2 py-0.5 rounded-full text-xs font-medium mr-1.5 mb-1.5 bg-yellow-200 text-yellow-800">{kw}</span>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {audit.content_analysis.keywords_missing_from_description?.length > 0 && (
                                    <div className="bg-yellow-50 rounded-lg p-3">
                                        <p className="text-sm font-medium text-yellow-800 mb-1">⚠ Keywords Missing from Meta Description</p>
                                        <p className="text-xs text-yellow-700">Add these to your meta description:</p>
                                        <div className="flex flex-wrap mt-2">
                                            {audit.content_analysis.keywords_missing_from_description.map((kw, i) => (
                                                <span key={i} className="inline-block px-2 py-0.5 rounded-full text-xs font-medium mr-1.5 mb-1.5 bg-yellow-200 text-yellow-800">{kw}</span>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </Section>
                    )}

                    {/* Meta Analysis */}
                    {audit.meta_analysis && (
                        <Section title="Meta Tags">
                            <div className="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p className="text-gray-500">Title</p>
                                    <p className="font-medium text-gray-900 mt-1">{audit.meta_analysis.title || '—'}</p>
                                    <p className="text-xs text-gray-400 mt-0.5">Length: {audit.meta_analysis.title_length ?? 0}</p>
                                </div>
                                <div>
                                    <p className="text-gray-500">Description</p>
                                    <p className="font-medium text-gray-900 mt-1">{audit.meta_analysis.description || '—'}</p>
                                    <p className="text-xs text-gray-400 mt-0.5">Length: {audit.meta_analysis.description_length ?? 0}</p>
                                </div>
                            </div>
                        </Section>
                    )}

                    {/* Security Analysis */}
                    {audit.security_analysis && (
                        <Section title="Security">
                            <div className="grid grid-cols-3 gap-4 text-sm">
                                <div className="flex items-center gap-2">
                                    <span className={audit.security_analysis.https ? 'text-green-500' : 'text-red-500'}>{audit.security_analysis.https ? '✓' : '✗'}</span>
                                    <span>HTTPS</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className={audit.security_analysis.hsts ? 'text-green-500' : 'text-yellow-500'}>{audit.security_analysis.hsts ? '✓' : '✗'}</span>
                                    <span>HSTS</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className={audit.security_analysis.csp ? 'text-green-500' : 'text-yellow-500'}>{audit.security_analysis.csp ? '✓' : '✗'}</span>
                                    <span>CSP</span>
                                </div>
                            </div>
                        </Section>
                    )}

                    {/* Heading Analysis */}
                    {audit.heading_analysis && (
                        <Section title="Headings">
                            <div className="grid grid-cols-3 gap-4 text-sm">
                                <div><span className="text-gray-500">H1:</span> <span className="font-medium">{audit.heading_analysis.h1_count ?? 0}</span></div>
                                <div><span className="text-gray-500">H2:</span> <span className="font-medium">{audit.heading_analysis.h2_count ?? 0}</span></div>
                                <div><span className="text-gray-500">H3:</span> <span className="font-medium">{audit.heading_analysis.h3_count ?? 0}</span></div>
                            </div>
                        </Section>
                    )}

                    {/* Image Analysis */}
                    {audit.image_analysis && (
                        <Section title="Images">
                            <div className="grid grid-cols-3 gap-4 text-sm">
                                <div><span className="text-gray-500">Total:</span> <span className="font-medium">{audit.image_analysis.total ?? 0}</span></div>
                                <div><span className="text-gray-500">Missing Alt:</span> <span className="font-medium text-red-600">{audit.image_analysis.missing_alt ?? 0}</span></div>
                                <div><span className="text-gray-500">Oversized:</span> <span className="font-medium text-yellow-600">{audit.image_analysis.oversized ?? 0}</span></div>
                            </div>
                        </Section>
                    )}

                    {/* Performance */}
                    {audit.performance_analysis && (
                        <Section title="Performance">
                            <div className="grid grid-cols-2 gap-4 text-sm">
                                <div><span className="text-gray-500">Load Time:</span> <span className="font-medium">{audit.performance_analysis.load_time ?? '—'}s</span></div>
                                <div><span className="text-gray-500">Page Size:</span> <span className="font-medium">{audit.performance_analysis.page_size ?? '—'}</span></div>
                            </div>
                        </Section>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
