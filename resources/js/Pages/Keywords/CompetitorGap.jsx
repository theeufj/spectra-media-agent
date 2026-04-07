import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

function UpgradePrompt() {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
            <div className="mx-auto w-12 h-12 bg-flame-orange-100 rounded-full flex items-center justify-center mb-4">
                <svg className="w-6 h-6 text-flame-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Competitor Keyword Gap Analysis</h3>
            <p className="text-sm text-gray-500 mb-4 max-w-md mx-auto">
                Discover keywords your competitors rank for that you're missing. Find untapped opportunities to grow your traffic.
            </p>
            <div className="flex flex-col items-center gap-3">
                <Link
                    href={route('subscription.pricing')}
                    className="inline-flex items-center px-5 py-2.5 bg-flame-orange-600 text-white text-sm font-medium rounded-lg hover:bg-flame-orange-700 transition"
                >
                    Upgrade to Growth Plan
                </Link>
                <p className="text-xs text-gray-400">Available on Growth ($249/mo) and Agency plans</p>
            </div>
        </div>
    );
}

export default function CompetitorGap({ gaps = [], competitors = [], ourKeywordCount, canAccessCompetitors = true }) {
    const handleAdd = (keyword) => {
        router.post(route('keywords.add-to-campaign'), {
            keywords: [{ text: keyword, match_type: 'PHRASE' }],
            source: 'competitor_gap',
        }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Competitor Keyword Gap" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Competitor Keyword Gap</h1>
                            <p className="mt-1 text-sm text-gray-500">
                                Keywords your competitors target that you don't. Your portfolio: {ourKeywordCount} keywords.
                            </p>
                        </div>
                        <a href={route('keywords.index')} className="text-sm text-gray-500 hover:text-gray-700">← Back to Keywords</a>
                    </div>

                    {!canAccessCompetitors ? (
                        <UpgradePrompt />
                    ) : (<>

                    {competitors.length > 0 && (
                        <div className="flex flex-wrap gap-2 mb-6">
                            {competitors.map(c => (
                                <span key={c.id} className="text-xs px-3 py-1 bg-gray-100 rounded-full text-gray-600">{c.domain}</span>
                            ))}
                        </div>
                    )}

                    {gaps.length > 0 ? (
                        <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Keyword</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Found On</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {gaps.map((gap, i) => (
                                        <tr key={i} className="hover:bg-gray-50">
                                            <td className="px-4 py-3 text-sm font-medium text-gray-900">{gap.keyword}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-1">
                                                    {gap.found_on?.map((domain, j) => (
                                                        <span key={j} className="text-xs px-2 py-0.5 bg-purple-50 text-purple-700 rounded">{domain}</span>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <button onClick={() => handleAdd(gap.keyword)} className="text-xs px-3 py-1.5 bg-flame-orange-50 text-flame-orange-700 rounded-lg hover:bg-flame-orange-100 font-medium">
                                                    + Add
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <h3 className="text-sm font-medium text-gray-900">No gaps found</h3>
                            <p className="mt-1 text-sm text-gray-500">Run competitor analysis first to discover keyword opportunities.</p>
                        </div>
                    )}

                    </>)}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
