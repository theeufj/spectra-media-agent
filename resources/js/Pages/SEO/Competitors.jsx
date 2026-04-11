import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

function UpgradePrompt() {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
            <div className="mx-auto w-12 h-12 bg-flame-orange-100 rounded-full flex items-center justify-center mb-4">
                <svg className="w-6 h-6 text-flame-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <h3 className="text-lg font-semibold text-gray-900 mb-2">Competitor Intelligence</h3>
            <p className="text-sm text-gray-500 mb-4 max-w-md mx-auto">
                Discover who your competitors are, analyze their messaging and pricing, and find keyword gaps — all powered by AI.
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

function CompetitorCard({ competitor }) {
    const [expanded, setExpanded] = useState(false);
    const c = competitor;

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            {/* Header */}
            <div className="flex items-center justify-between mb-3">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900">{c.name || c.domain}</h3>
                    <p className="text-xs text-gray-500">{c.domain}</p>
                </div>
                <div className="flex items-center gap-3">
                    {c.discovery_source && (
                        <span className="text-[10px] px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full capitalize">{c.discovery_source.replace(/_/g, ' ')}</span>
                    )}
                    {c.last_analyzed_at && (
                        <span className="text-[10px] text-gray-400">Analyzed {new Date(c.last_analyzed_at).toLocaleDateString()}</span>
                    )}
                </div>
            </div>

            {/* Auction Metrics */}
            {(c.impression_share || c.overlap_rate || c.position_above_rate) && (
                <div className="flex gap-4 mb-3">
                    {c.impression_share != null && (
                        <div className="text-center">
                            <p className="text-lg font-semibold text-blue-600">{(Number(c.impression_share)).toFixed(1)}%</p>
                            <p className="text-[10px] text-gray-400">Impression Share</p>
                        </div>
                    )}
                    {c.overlap_rate != null && (
                        <div className="text-center">
                            <p className="text-lg font-semibold text-purple-600">{(Number(c.overlap_rate)).toFixed(1)}%</p>
                            <p className="text-[10px] text-gray-400">Overlap Rate</p>
                        </div>
                    )}
                    {c.position_above_rate != null && (
                        <div className="text-center">
                            <p className="text-lg font-semibold text-amber-600">{(Number(c.position_above_rate)).toFixed(1)}%</p>
                            <p className="text-[10px] text-gray-400">Position Above</p>
                        </div>
                    )}
                </div>
            )}

            {/* Keywords */}
            {c.keywords_detected && c.keywords_detected.length > 0 && (
                <div className="mb-3">
                    <p className="text-xs text-gray-500 mb-1">Keywords Detected:</p>
                    <div className="flex flex-wrap gap-1">
                        {(Array.isArray(c.keywords_detected) ? c.keywords_detected : []).slice(0, expanded ? undefined : 8).map((kw, i) => (
                            <span key={i} className="text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded">{kw}</span>
                        ))}
                        {!expanded && c.keywords_detected.length > 8 && (
                            <button onClick={() => setExpanded(true)} className="text-xs text-flame-orange-600 hover:underline">+{c.keywords_detected.length - 8} more</button>
                        )}
                    </div>
                </div>
            )}

            {/* Messaging Analysis */}
            {c.messaging_analysis && (
                <div className="mb-3">
                    <p className="text-xs font-medium text-gray-700 mb-1">Messaging Analysis</p>
                    {typeof c.messaging_analysis === 'string' ? (
                        <p className="text-xs text-gray-500">{c.messaging_analysis}</p>
                    ) : (
                        <div className="space-y-1">
                            {c.messaging_analysis.competition_type && (
                                <p className="text-xs text-gray-500">
                                    <span className="font-medium capitalize">{c.messaging_analysis.competition_type}</span> competitor
                                    {c.messaging_analysis.estimated_size && <span className="text-gray-400"> · {c.messaging_analysis.estimated_size}</span>}
                                </p>
                            )}
                            {c.messaging_analysis.why_competitor && (
                                <p className="text-xs text-gray-500">{c.messaging_analysis.why_competitor}</p>
                            )}
                            {c.messaging_analysis.summary && (
                                <p className="text-xs text-gray-500">{c.messaging_analysis.summary}</p>
                            )}
                            {c.messaging_analysis.tone && (
                                <p className="text-xs text-gray-500">Tone: {c.messaging_analysis.tone}</p>
                            )}
                            {c.messaging_analysis.counter_strategy && (
                                <p className="text-xs text-gray-500 italic">Counter: {typeof c.messaging_analysis.counter_strategy === 'string' ? c.messaging_analysis.counter_strategy : c.messaging_analysis.counter_strategy.recommendation || JSON.stringify(c.messaging_analysis.counter_strategy)}</p>
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* Value Propositions */}
            {c.value_propositions && (Array.isArray(c.value_propositions) ? c.value_propositions.length > 0 : Object.keys(c.value_propositions).length > 0) && (
                <div className="mb-3">
                    <p className="text-xs font-medium text-gray-700 mb-1">Value Propositions</p>
                    {Array.isArray(c.value_propositions) ? (
                        <ul className="list-disc list-inside text-xs text-gray-500 space-y-0.5">
                            {c.value_propositions.slice(0, 5).map((vp, i) => (
                                <li key={i}>{typeof vp === 'string' ? vp : vp.description || JSON.stringify(vp)}</li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-xs text-gray-500">{JSON.stringify(c.value_propositions)}</p>
                    )}
                </div>
            )}

            {/* Pricing Info */}
            {c.pricing_info && (
                <div className="mb-3">
                    <p className="text-xs font-medium text-gray-700 mb-1">Pricing Intel</p>
                    <p className="text-xs text-gray-500">{typeof c.pricing_info === 'string' ? c.pricing_info : (c.pricing_info.summary || c.pricing_info.positioning || JSON.stringify(c.pricing_info))}</p>
                </div>
            )}
        </div>
    );
}

function StrategySection({ strategy, updatedAt }) {
    if (!strategy) return null;

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-6 space-y-5">
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-gray-900">AI Competitive Strategy</h2>
                    <p className="text-xs text-gray-400 mt-0.5">Generated by competitive intelligence agent</p>
                </div>
                {updatedAt && (
                    <span className="text-xs text-gray-400">Updated {new Date(updatedAt).toLocaleDateString()}</span>
                )}
            </div>

            {/* Positioning */}
            {strategy.positioning_strategy && (
                <div>
                    <h3 className="text-sm font-medium text-gray-800 mb-1">Positioning Strategy</h3>
                    {strategy.positioning_strategy.primary_angle && (
                        <p className="text-sm text-gray-600 mb-1"><strong>Primary angle:</strong> {strategy.positioning_strategy.primary_angle}</p>
                    )}
                    {Array.isArray(strategy.positioning_strategy.supporting_points) && (
                        <ul className="list-disc list-inside text-xs text-gray-500 space-y-0.5">
                            {strategy.positioning_strategy.supporting_points.map((p, i) => <li key={i}>{p}</li>)}
                        </ul>
                    )}
                </div>
            )}

            {/* Messaging Recommendations */}
            {strategy.messaging_recommendations && (
                <div>
                    <h3 className="text-sm font-medium text-gray-800 mb-1">Messaging Recommendations</h3>
                    {Array.isArray(strategy.messaging_recommendations.headline_themes) && (
                        <div className="mb-2">
                            <p className="text-xs text-gray-500 mb-1">Headline Themes:</p>
                            <div className="flex flex-wrap gap-1">
                                {strategy.messaging_recommendations.headline_themes.map((t, i) => (
                                    <span key={i} className="text-xs px-2 py-0.5 bg-blue-50 text-blue-700 rounded">{t}</span>
                                ))}
                            </div>
                        </div>
                    )}
                    {Array.isArray(strategy.messaging_recommendations.key_messages) && (
                        <ul className="list-disc list-inside text-xs text-gray-500 space-y-0.5">
                            {strategy.messaging_recommendations.key_messages.map((m, i) => <li key={i}>{m}</li>)}
                        </ul>
                    )}
                </div>
            )}

            {/* Keyword Strategy */}
            {strategy.keyword_strategy && (
                <div>
                    <h3 className="text-sm font-medium text-gray-800 mb-1">Keyword Strategy</h3>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {Array.isArray(strategy.keyword_strategy.attack_keywords) && strategy.keyword_strategy.attack_keywords.length > 0 && (
                            <div>
                                <p className="text-xs text-gray-500 mb-1">Attack Keywords</p>
                                <div className="flex flex-wrap gap-1">
                                    {strategy.keyword_strategy.attack_keywords.slice(0, 10).map((kw, i) => (
                                        <span key={i} className="text-xs px-2 py-0.5 bg-red-50 text-red-700 rounded">{kw}</span>
                                    ))}
                                </div>
                            </div>
                        )}
                        {Array.isArray(strategy.keyword_strategy.opportunity_keywords) && strategy.keyword_strategy.opportunity_keywords.length > 0 && (
                            <div>
                                <p className="text-xs text-gray-500 mb-1">Opportunity Keywords</p>
                                <div className="flex flex-wrap gap-1">
                                    {strategy.keyword_strategy.opportunity_keywords.slice(0, 10).map((kw, i) => (
                                        <span key={i} className="text-xs px-2 py-0.5 bg-green-50 text-green-700 rounded">{kw}</span>
                                    ))}
                                </div>
                            </div>
                        )}
                        {Array.isArray(strategy.keyword_strategy.defense_keywords) && strategy.keyword_strategy.defense_keywords.length > 0 && (
                            <div>
                                <p className="text-xs text-gray-500 mb-1">Defense Keywords</p>
                                <div className="flex flex-wrap gap-1">
                                    {strategy.keyword_strategy.defense_keywords.slice(0, 10).map((kw, i) => (
                                        <span key={i} className="text-xs px-2 py-0.5 bg-amber-50 text-amber-700 rounded">{kw}</span>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Ad Copy Examples */}
            {Array.isArray(strategy.ad_copy_examples) && strategy.ad_copy_examples.length > 0 && (
                <div>
                    <h3 className="text-sm font-medium text-gray-800 mb-2">Ad Copy Examples</h3>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {strategy.ad_copy_examples.slice(0, 4).map((ad, i) => (
                            <div key={i} className="bg-gray-50 rounded-lg p-3">
                                {ad.headline && <p className="text-xs font-medium text-gray-900">{ad.headline}</p>}
                                {ad.description && <p className="text-xs text-gray-500 mt-0.5">{ad.description}</p>}
                                {ad.competitor && <p className="text-[10px] text-gray-400 mt-1">Counter: {ad.competitor}</p>}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Quick Wins */}
            {Array.isArray(strategy.quick_wins) && strategy.quick_wins.length > 0 && (
                <div>
                    <h3 className="text-sm font-medium text-gray-800 mb-1">Quick Wins</h3>
                    <ul className="space-y-1">
                        {strategy.quick_wins.map((w, i) => (
                            <li key={i} className="flex items-start gap-2 text-xs text-gray-600">
                                <span className="text-green-500 mt-0.5">✓</span>
                                <span>{typeof w === 'string' ? w : w.action || JSON.stringify(w)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Long Term Plays */}
            {Array.isArray(strategy.long_term_plays) && strategy.long_term_plays.length > 0 && (
                <div>
                    <h3 className="text-sm font-medium text-gray-800 mb-1">Long-Term Plays</h3>
                    <ul className="space-y-1">
                        {strategy.long_term_plays.map((p, i) => (
                            <li key={i} className="flex items-start gap-2 text-xs text-gray-600">
                                <span className="text-blue-500 mt-0.5">→</span>
                                <span>{typeof p === 'string' ? p : p.action || JSON.stringify(p)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Bidding Recommendations */}
            {strategy.bidding_recommendations && (
                <div>
                    <h3 className="text-sm font-medium text-gray-800 mb-1">Bidding Recommendations</h3>
                    {strategy.bidding_recommendations.target_impression_share && (
                        <p className="text-xs text-gray-600">Target Impression Share: <strong>{strategy.bidding_recommendations.target_impression_share}</strong></p>
                    )}
                    {Array.isArray(strategy.bidding_recommendations.aggressive_times) && (
                        <p className="text-xs text-gray-500 mt-0.5">Aggressive times: {strategy.bidding_recommendations.aggressive_times.join(', ')}</p>
                    )}
                    {strategy.bidding_recommendations.budget_allocation && (
                        <p className="text-xs text-gray-500 mt-0.5">Budget: {typeof strategy.bidding_recommendations.budget_allocation === 'string' ? strategy.bidding_recommendations.budget_allocation : JSON.stringify(strategy.bidding_recommendations.budget_allocation)}</p>
                    )}
                </div>
            )}
        </div>
    );
}

export default function Competitors({ domain, competitors = [], canAccessCompetitors = true, competitiveStrategy = null, strategyUpdatedAt = null, lastAnalyzedAt = null }) {
    const [refreshing, setRefreshing] = useState(false);

    const handleRefresh = () => {
        setRefreshing(true);
        router.post(route('seo.competitors.refresh'), {}, {
            preserveScroll: true,
            onFinish: () => setRefreshing(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="SEO Competitors" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <a href={route('seo.index')} className="text-sm text-flame-orange-600 hover:underline mb-1 inline-block">← Back to SEO</a>
                    <div className="flex items-center justify-between mb-1">
                        <h1 className="text-2xl font-bold text-gray-900">Competitor Analysis</h1>
                        {canAccessCompetitors && (
                            <div className="flex items-center gap-3">
                                {lastAnalyzedAt && (
                                    <span className="text-xs text-gray-400">Last run: {new Date(lastAnalyzedAt).toLocaleDateString()}</span>
                                )}
                                <button
                                    onClick={handleRefresh}
                                    disabled={refreshing}
                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <svg className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    {refreshing ? 'Starting...' : 'Refresh Analysis'}
                                </button>
                            </div>
                        )}
                    </div>
                    <p className="text-sm text-gray-500 mb-6">{domain ? `Your domain: ${domain}` : 'Set your website URL to compare with competitors.'}</p>

                    {!canAccessCompetitors ? (
                        <UpgradePrompt />
                    ) : (
                        <div className="space-y-6">
                            {/* Competitive Strategy */}
                            <StrategySection strategy={competitiveStrategy} updatedAt={strategyUpdatedAt} />

                            {/* Competitor Cards */}
                            {competitors.length === 0 ? (
                                <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
                                    <p className="text-gray-500">No competitors tracked yet. Click "Refresh Analysis" to discover competitors automatically.</p>
                                </div>
                            ) : (
                                <>
                                    <h2 className="text-lg font-semibold text-gray-900">Competitors ({competitors.length})</h2>
                                    <div className="space-y-4">
                                        {competitors.map((c) => (
                                            <CompetitorCard key={c.id} competitor={c} />
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
