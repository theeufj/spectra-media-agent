import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

function Section({ title, children }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">{title}</h2>
            {children}
        </div>
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

                    {/* Recommendations */}
                    {audit.recommendations && audit.recommendations.length > 0 && (
                        <Section title="AI Recommendations">
                            <IssueList items={audit.recommendations} type="info" />
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
