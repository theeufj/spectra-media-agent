import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Competitors({ domain, competitors = [] }) {
    return (
        <AuthenticatedLayout>
            <Head title="SEO Competitors" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <a href={route('seo.index')} className="text-sm text-flame-orange-600 hover:underline mb-1 inline-block">← Back to SEO</a>
                    <h1 className="text-2xl font-bold text-gray-900 mb-1">Competitor Analysis</h1>
                    <p className="text-sm text-gray-500 mb-6">{domain ? `Your domain: ${domain}` : 'Set your website URL to compare with competitors.'}</p>

                    {competitors.length === 0 ? (
                        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
                            <p className="text-gray-500">No competitors tracked yet. Add competitors from the Campaigns page to start tracking.</p>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {competitors.map((c) => (
                                <div key={c.id} className="bg-white rounded-lg border border-gray-200 p-5">
                                    <div className="flex items-center justify-between mb-3">
                                        <div>
                                            <h3 className="text-sm font-semibold text-gray-900">{c.name || c.domain}</h3>
                                            <p className="text-xs text-gray-500">{c.domain}</p>
                                        </div>
                                        {c.impression_share && (
                                            <span className="text-sm font-medium text-blue-600">{(c.impression_share * 100).toFixed(1)}% impression share</span>
                                        )}
                                    </div>
                                    {c.keywords_detected && c.keywords_detected.length > 0 && (
                                        <div className="mt-2">
                                            <p className="text-xs text-gray-500 mb-1">Shared Keywords:</p>
                                            <div className="flex flex-wrap gap-1">
                                                {(Array.isArray(c.keywords_detected) ? c.keywords_detected : []).slice(0, 8).map((kw, i) => (
                                                    <span key={i} className="text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded">{kw}</span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
