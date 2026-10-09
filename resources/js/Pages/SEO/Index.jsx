import IndexingHealthReport from '@/Components/IndexingHealthReport';
import WorkStatusBanner from '@/Components/WorkStatusBanner';
import FormErrorSummary from '@/Components/FormErrorSummary';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router, Link } from '@inertiajs/react';
import { useState } from 'react';
import { brandTint } from '@/Components/Marketing/Hero';

function ScoreRing({ score }) {
    /*
     * An em dash in alarm red reads as a failing score rather than "not run
     * yet". The audit has never been run on a new account, so the first thing
     * the page said was that something was wrong.
     */
    const hasScore = score !== null && score !== undefined;
    const color = !hasScore ? 'text-gray-400'
        : score >= 80 ? 'text-green-600' : score >= 60 ? 'text-yellow-600' : 'text-red-600';
    return (
        <div className="flex flex-col items-center">
            <span className={`text-4xl font-bold ${color}`}>{hasScore ? score : '—'}</span>
            <span className="text-xs text-gray-500 mt-1">{hasScore ? 'SEO score' : 'Not audited yet'}</span>
        </div>
    );
}

function StatCard({ label, value, color }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <p className="text-xs text-gray-500">{label}</p>
            <p className={`text-xl font-bold mt-1 ${color || 'text-gray-900'}`}>{value}</p>
        </div>
    );
}

export default function Index({ latestAudit, audits = [], rankingSummary, topRankings = [], competitors = [], domain, auditRun = null, indexingAudit = null, indexingRun = null, searchConsoleConnection = null, verificationRun = null }) {
    const form = useForm({ url: auditRun?.context?.url || (domain ? `https://${domain}` : '') });
    const [verificationBusy, setVerificationBusy] = useState(['queued', 'running'].includes(verificationRun?.status));
    const [verificationSubmitting, setVerificationSubmitting] = useState(false);
    const [indexingBusy, setIndexingBusy] = useState(['queued', 'running'].includes(indexingRun?.status));
    const [indexingSubmitting, setIndexingSubmitting] = useState(false);
    const [workBusy, setWorkBusy] = useState(['queued', 'running'].includes(auditRun?.status));
    const running = form.processing || workBusy;

    const handleRunAudit = (e) => {
        e.preventDefault();
        form.post(route('seo.audit'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="SEO Tools" />
            <div className="py-8">
                <div className="mx-auto max-w-6xl">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">SEO Tools & Optimization</h1>
                            <p className="mt-1 text-sm text-gray-500">Audit your site, track rankings, and analyze backlinks.</p>
                        </div>
                        <div className="flex gap-2">
                            <Link href={route('seo.rankings')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">Rankings</Link>
                            <Link href={route('seo.backlinks')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">Backlinks</Link>
                            <Link href={route('seo.competitors')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">Competitors</Link>
                        </div>
                    </div>

                    {searchConsoleConnection && !searchConsoleConnection.bound && (
                        <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-5">
                            <h2 className="font-semibold text-gray-900">Verify website ownership for organic search reports</h2>
                            <p className="mt-2 text-sm text-gray-700">Install the tracking container assigned to this customer on {searchConsoleConnection.host || 'your website'}, then verify ownership. Changing the website requires verification again.</p>
                            {!searchConsoleConnection.can_verify && searchConsoleConnection.customer_uuid && <Link href={route('customers.gtm.setup', searchConsoleConnection.customer_uuid)} className="mt-3 inline-block text-sm text-brand-dark underline">Set up your assigned tracking container</Link>}
                            <button disabled={!searchConsoleConnection.can_verify || verificationBusy || verificationSubmitting} onClick={() => {
                                setVerificationSubmitting(true);
                                router.post(route('seo.search-console.verify'), {}, { preserveScroll: true, onFinish: () => setVerificationSubmitting(false) });
                            }} className="ml-3 mt-3 rounded-lg border border-gray-300 px-4 py-2 text-sm disabled:opacity-50">Verify website ownership</button>
                            <p className="mt-3 text-xs text-gray-600">Contact support if website verification cannot finish. We can help confirm your website ownership and Search Console access.</p>
                        </div>
                    )}
                    <WorkStatusBanner initialRun={verificationRun} url={route('seo.work-status')} task="search-console-verification" label="Website ownership verification" reloadOnly={['searchConsoleConnection', 'verificationRun']} onBusyChange={setVerificationBusy} />

                    {/* Run Audit */}
                    <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                        <h2 className="text-lg font-semibold text-gray-900 mb-3">Run SEO Audit</h2>
                        <FormErrorSummary errors={form.errors} labels={{ url: 'Website URL' }} className="mb-3" />
                        <form onSubmit={handleRunAudit} className="flex flex-col gap-3 sm:flex-row">
                            <input id="url" aria-label="Website URL"
                                type="url"
                                value={form.data.url}
                                onChange={(e) => form.setData('url', e.target.value)}
                                placeholder="https://example.com"
                                className="flex-1 rounded-lg border-gray-300 text-sm focus:ring-brand-primary focus:border-brand-primary"
                                required
                            />
                            <button
                                type="submit"
                                disabled={running}
                                className="px-6 py-2 bg-brand-dark text-white rounded-lg text-sm font-medium hover:bg-brand-darker disabled:opacity-50"
                            >
                                {running ? 'Starting...' : 'Run Audit'}
                            </button>
                        </form>
                    </div>

                    <WorkStatusBanner initialRun={auditRun} url={route('seo.work-status')} task="seo-audit" label="SEO audit" reloadOnly={['latestAudit', 'audits', 'auditRun']} onBusyChange={setWorkBusy} />
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <button disabled={indexingBusy || indexingSubmitting} onClick={() => {
                            setIndexingSubmitting(true);
                            router.post(route('seo.indexing.check'), {}, { preserveScroll: true, onFinish: () => setIndexingSubmitting(false) });
                        }} className="px-4 py-2 text-sm border border-gray-300 rounded-lg disabled:opacity-50">Check Google indexing</button>
                        <p className="text-sm text-gray-500">Checks sitemap pages for exclusions and canonical conflicts.</p>
                    </div>
                    <WorkStatusBanner initialRun={indexingRun} url={route('seo.work-status')} task="indexing" label="Google indexing check" reloadOnly={['indexingAudit', 'indexingRun', 'audits']} onBusyChange={setIndexingBusy} />
                    <IndexingHealthReport report={indexingAudit?.indexing_analysis} />
                    {/* Overview */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <div className="bg-white rounded-lg border border-gray-200 p-6 flex justify-center">
                            <ScoreRing score={latestAudit?.score} />
                        </div>
                        <StatCard label="Organic queries measured" value={rankingSummary?.total_keywords ?? 0} />
                        <StatCard label="Mean query position" value={rankingSummary?.avg_position ? rankingSummary.avg_position.toFixed(1) : '—'} />
                        <StatCard label="Average position ≤ 10" value={rankingSummary?.top_10_count ?? 0} color="text-green-600" />
                    </div>

                    {/* Latest Issues */}
                    {latestAudit?.issues && latestAudit.issues.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-3">Issues Found</h2>
                            <div className="space-y-2">
                                {latestAudit.issues.map((issue, i) => (
                                    <div key={i} className="flex items-start gap-3 p-3 bg-red-50 rounded-lg">
                                        <span className="text-red-500 mt-0.5">⚠</span>
                                        <div>
                                            <p className="text-sm font-medium text-gray-900">{issue.title || issue.message || (typeof issue === 'string' ? issue : JSON.stringify(issue))}</p>
                                            {(issue.description || issue.category) && <p className="text-xs text-gray-500 mt-0.5">{issue.description || issue.category}</p>}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Quick Wins from Latest Audit */}
                    {latestAudit?.recommendations && latestAudit.recommendations.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <div className="flex items-center justify-between mb-3">
                                <h2 className="text-lg font-semibold text-gray-900">Quick Wins</h2>
                                {latestAudit?.id && (
                                    <Link href={route('seo.audit.detail', latestAudit.id)} className="text-sm text-brand-dark hover:underline">View Full Audit</Link>
                                )}
                            </div>
                            <div className="space-y-3">
                                {latestAudit.recommendations.filter(r => r.priority === 'high').slice(0, 3).map((rec, i) => (
                                    <div key={i} className="border border-gray-100 rounded-lg p-3">
                                        <div className="flex items-center gap-2 mb-1">
                                            <span className="text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-medium">{rec.priority}</span>
                                            <span className="text-xs text-gray-500">{rec.category}</span>
                                        </div>
                                        <p className="text-sm font-medium text-gray-900">{rec.message || (typeof rec === 'string' ? rec : JSON.stringify(rec))}</p>
                                        {rec.action && (
                                            <div className="mt-2 bg-gray-50 rounded p-2">
                                                <code className="text-xs text-gray-600 break-all">{rec.action}</code>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Keyword Snapshot from Latest Audit */}
                    {latestAudit?.content_analysis?.detected_keywords?.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <div className="flex items-center justify-between mb-3">
                                <h2 className="text-lg font-semibold text-gray-900">Top Keywords Detected</h2>
                                {latestAudit?.id && (
                                    <Link href={route('seo.audit.detail', latestAudit.id)} className="text-sm text-brand-dark hover:underline">Full Analysis</Link>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-1.5 mb-3">
                                {latestAudit.content_analysis.detected_keywords.slice(0, 15).map((kw, i) => (
                                    <span key={i} className="inline-block px-2.5 py-1 rounded-full text-xs font-medium text-brand-darker" style={{ backgroundColor: brandTint(10) }}>{kw}</span>
                                ))}
                            </div>
                            {(latestAudit.content_analysis.keywords_missing_from_title?.length > 0 || latestAudit.content_analysis.keywords_missing_from_description?.length > 0) && (
                                <div className="bg-yellow-50 rounded-lg p-3 mt-2">
                                    <p className="text-sm font-medium text-yellow-800">Keywords missing from your meta tags:</p>
                                    <div className="flex flex-wrap gap-1.5 mt-1.5">
                                        {[...(latestAudit.content_analysis.keywords_missing_from_title || []), ...(latestAudit.content_analysis.keywords_missing_from_description || [])]
                                            .filter((v, i, a) => a.indexOf(v) === i)
                                            .map((kw, i) => (
                                                <span key={i} className="inline-block px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-200 text-yellow-800">{kw}</span>
                                            ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Top Rankings */}
                    {topRankings.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <div className="flex items-center justify-between mb-3">
                                <h2 className="text-lg font-semibold text-gray-900">Top Rankings</h2>
                                <Link href={route('seo.rankings')} className="text-sm text-brand-dark hover:underline">View All</Link>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead><tr className="text-left text-gray-500 border-b">
                                        <th className="pb-2 font-medium">Keyword</th>
                                        <th className="pb-2 font-medium">Position</th>
                                        <th className="pb-2 font-medium">Change</th>
                                        <th className="pb-2 font-medium">URL</th>
                                    </tr></thead>
                                    <tbody>
                                        {topRankings.map((r, i) => (
                                            <tr key={i} className="border-b border-gray-100">
                                                <td className="py-2 font-medium text-gray-900">{r.keyword}</td>
                                                <td className="py-2">{r.average_position != null ? r.average_position.toFixed(1) : r.position}</td>
                                                <td className="py-2">
                                                    {(r.average_change ?? r.change) > 0 && <span className="text-green-600">↑{r.average_change != null ? r.average_change.toFixed(1) : r.change}</span>}
                                                    {(r.average_change ?? r.change) < 0 && <span className="text-red-600">↓{Math.abs(r.average_change ?? r.change).toFixed(1)}</span>}
                                                    {(r.average_change ?? r.change) === 0 && <span className="text-gray-500">—</span>}
                                                </td>
                                                <td className="py-2 text-gray-500 truncate max-w-xs">{r.url}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Past Audits */}
                    {audits.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-3">Audit History</h2>
                            <div className="space-y-2">
                                {audits.map((audit) => (
                                    <Link key={audit.id} href={route('seo.audit.detail', audit.id)} className="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50 border border-gray-100">
                                        <div>
                                            <p className="text-sm font-medium text-gray-900">{audit.url}</p>
                                            <p className="text-xs text-gray-500">{new Date(audit.created_at).toLocaleDateString()}</p>
                                        </div>
                                        <span className={`text-lg font-bold ${audit.score >= 80 ? 'text-green-600' : audit.score >= 60 ? 'text-yellow-600' : 'text-red-600'}`}>
                                            {audit.score ?? 'Indexing check'}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
