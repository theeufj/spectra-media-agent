import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

function MetricCard({ label, value, color }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <p className="text-xs text-gray-500">{label}</p>
            <p className={`text-xl font-bold mt-1 ${color || 'text-gray-900'}`}>{value}</p>
        </div>
    );
}

export default function Backlinks({ profile, domain, error }) {
    return (
        <AuthenticatedLayout>
            <Head title="Backlink Analysis" />
            <div className="py-8">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <a href={route('seo.index')} className="text-sm text-flame-orange-600 hover:underline mb-1 inline-block">← Back to SEO</a>
                    <h1 className="text-2xl font-bold text-gray-900 mb-1">Backlink Analysis</h1>
                    <p className="text-sm text-gray-500 mb-6">{domain ? `Analyzing: ${domain}` : 'Set your website to analyze backlinks.'}</p>

                    {error && (
                        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6 text-sm text-yellow-800">{error}</div>
                    )}

                    {profile && (
                        <>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                                <MetricCard label="Total Backlinks" value={profile.total_backlinks ?? 0} />
                                <MetricCard label="Referring Domains" value={profile.referring_domains ?? 0} />
                                <MetricCard label="Domain Authority" value={profile.domain_authority ?? '—'} color="text-blue-600" />
                                <MetricCard label="Toxic Links" value={profile.toxic_count ?? 0} color={profile.toxic_count > 0 ? 'text-red-600' : 'text-green-600'} />
                            </div>

                            {/* Anchor Distribution */}
                            {profile.anchor_analysis && profile.anchor_analysis.length > 0 && (
                                <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                                    <h2 className="text-lg font-semibold text-gray-900 mb-3">Anchor Text Distribution</h2>
                                    <div className="space-y-2">
                                        {profile.anchor_analysis.slice(0, 10).map((a, i) => (
                                            <div key={i} className="flex items-center justify-between">
                                                <span className="text-sm text-gray-700">{a.text || a.anchor}</span>
                                                <div className="flex items-center gap-2">
                                                    <div className="w-32 bg-gray-200 rounded-full h-2">
                                                        <div className="bg-flame-orange-500 h-2 rounded-full" style={{ width: `${Math.min(a.percentage || 0, 100)}%` }} />
                                                    </div>
                                                    <span className="text-xs text-gray-500 w-10 text-right">{a.percentage?.toFixed(1) ?? 0}%</span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Opportunities */}
                            {profile.opportunities && profile.opportunities.length > 0 && (
                                <div className="bg-white rounded-lg border border-gray-200 p-6">
                                    <h2 className="text-lg font-semibold text-gray-900 mb-3">Link Building Opportunities</h2>
                                    <div className="space-y-2">
                                        {profile.opportunities.map((opp, i) => (
                                            <div key={i} className="p-3 bg-blue-50 rounded-lg text-sm text-blue-800">
                                                {typeof opp === 'string' ? opp : opp.description || JSON.stringify(opp)}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </>
                    )}

                    {!profile && !error && (
                        <div className="text-center py-12 text-gray-500">Loading backlink data...</div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
