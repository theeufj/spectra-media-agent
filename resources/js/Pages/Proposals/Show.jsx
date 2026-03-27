import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';

function GeneratingState() {
    return (
        <div className="bg-white rounded-lg shadow-md p-16 text-center">
            <div className="relative w-20 h-20 mx-auto mb-6">
                <div className="absolute inset-0 rounded-full border-4 border-flame-orange-200 animate-ping opacity-25" />
                <div className="relative w-20 h-20 rounded-full border-4 border-flame-orange-500 border-t-transparent animate-spin" />
            </div>
            <h2 className="text-xl font-bold text-gray-900 mb-2">Generating Your Proposal</h2>
            <p className="text-gray-500 max-w-md mx-auto">
                Our AI is analyzing the client's website, researching the industry, and crafting a tailored advertising proposal. This usually takes 1-2 minutes.
            </p>
            <div className="mt-8 flex justify-center gap-3">
                {['Analyzing website', 'Researching industry', 'Building strategies', 'Generating PDF'].map((step, i) => (
                    <span key={step} className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-flame-orange-50 text-flame-orange-600 animate-pulse" style={{ animationDelay: `${i * 0.5}s` }}>
                        {step}
                    </span>
                ))}
            </div>
        </div>
    );
}

function FailedState({ error }) {
    return (
        <div className="bg-white rounded-lg shadow-md p-12 text-center">
            <svg className="mx-auto h-16 w-16 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
            <h2 className="mt-4 text-xl font-bold text-gray-900">Proposal Generation Failed</h2>
            <p className="mt-2 text-sm text-gray-500 max-w-md mx-auto">{error || 'An unexpected error occurred. Please try again.'}</p>
            <Link
                href={route('proposals.create')}
                className="mt-6 inline-flex items-center px-5 py-2.5 bg-flame-orange-600 text-white rounded-lg hover:bg-flame-orange-700 transition font-medium"
            >
                Try Again
            </Link>
        </div>
    );
}

function ProposalPreview({ proposal, data }) {
    return (
        <div className="space-y-8">
            {/* Header actions */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-3xl font-bold text-gray-900">{proposal.client_name}</h1>
                    <p className="text-gray-500">{proposal.industry || 'Digital Advertising'} &middot; ${Number(proposal.budget).toLocaleString()}/mo</p>
                </div>
                <a
                    href={route('proposals.export-pdf', proposal.id)}
                    className="inline-flex items-center px-5 py-2.5 bg-flame-orange-600 text-white rounded-lg hover:bg-flame-orange-700 transition font-medium shadow-md"
                >
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Download PDF
                </a>
            </div>

            {/* Executive Summary */}
            {data.executive_summary && (
                <div className="bg-white rounded-lg shadow-md overflow-hidden">
                    <div className="bg-gradient-to-r from-flame-orange-600 to-flame-orange-700 px-6 py-4">
                        <h2 className="text-lg font-semibold text-white">Executive Summary</h2>
                    </div>
                    <div className="p-6">
                        <p className="text-gray-700 leading-relaxed whitespace-pre-line">{data.executive_summary}</p>
                    </div>
                </div>
            )}

            {/* Industry Analysis */}
            {data.industry_analysis && (
                <div className="bg-white rounded-lg shadow-md overflow-hidden">
                    <div className="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
                        <h2 className="text-lg font-semibold text-white">Industry Analysis</h2>
                    </div>
                    <div className="p-6">
                        <p className="text-gray-700 leading-relaxed whitespace-pre-line">{data.industry_analysis}</p>
                    </div>
                </div>
            )}

            {/* Platform Strategies */}
            {data.platform_strategies?.map((strategy, idx) => (
                <div key={idx} className="bg-white rounded-lg shadow-md overflow-hidden">
                    <div className="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4 flex justify-between items-center">
                        <h2 className="text-lg font-semibold text-white">{strategy.platform}</h2>
                        {strategy.budget_allocation && (
                            <span className="bg-blue-500 text-white px-3 py-1 rounded-full text-sm font-medium">
                                ${Number(strategy.budget_allocation).toLocaleString()}/mo
                            </span>
                        )}
                    </div>
                    <div className="p-6">
                        {strategy.campaign_types && (
                            <div className="flex flex-wrap gap-2 mb-4">
                                {strategy.campaign_types.map((type) => (
                                    <span key={type} className="inline-block bg-blue-50 text-blue-700 text-xs px-2.5 py-1 rounded-full font-medium">
                                        {type}
                                    </span>
                                ))}
                            </div>
                        )}

                        <p className="text-gray-700 leading-relaxed whitespace-pre-line mb-4">{strategy.strategy_overview}</p>

                        {strategy.targeting_approach && (
                            <>
                                <h3 className="text-sm font-semibold text-gray-900 mb-1">Targeting Approach</h3>
                                <p className="text-gray-600 text-sm mb-4">{strategy.targeting_approach}</p>
                            </>
                        )}

                        {strategy.expected_metrics && (
                            <>
                                <h3 className="text-sm font-semibold text-gray-900 mb-2">Expected Performance</h3>
                                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
                                    {Object.entries(strategy.expected_metrics).map(([key, val]) => (
                                        <div key={key} className="bg-blue-50 rounded-lg p-3 text-center">
                                            <div className="text-xs text-gray-500 uppercase">{key.replace('estimated_', '').replace('_', ' ')}</div>
                                            <div className="text-sm font-bold text-blue-700 mt-1">{val}</div>
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}

                        {strategy.sample_ad_concepts?.length > 0 && (
                            <>
                                <h3 className="text-sm font-semibold text-gray-900 mb-2">Sample Ad Concepts</h3>
                                <div className="space-y-3">
                                    {strategy.sample_ad_concepts.map((ad, adIdx) => (
                                        <div key={adIdx} className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                            <div className="text-blue-700 font-bold text-sm">{ad.headline}</div>
                                            <div className="text-gray-600 text-sm mt-1">{ad.description}</div>
                                            {ad.call_to_action && (
                                                <div className="text-flame-orange-600 text-xs font-semibold mt-2">{ad.call_to_action}</div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                    </div>
                </div>
            ))}

            {/* Timeline */}
            {data.timeline?.length > 0 && (
                <div className="bg-white rounded-lg shadow-md overflow-hidden">
                    <div className="bg-gradient-to-r from-green-600 to-green-700 px-6 py-4">
                        <h2 className="text-lg font-semibold text-white">Implementation Timeline</h2>
                    </div>
                    <div className="p-6">
                        <div className="space-y-6">
                            {data.timeline.map((phase, idx) => (
                                <div key={idx} className="flex">
                                    <div className="flex flex-col items-center mr-4">
                                        <div className="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center text-sm font-bold">
                                            {idx + 1}
                                        </div>
                                        {idx < data.timeline.length - 1 && <div className="w-0.5 flex-1 bg-green-200 mt-1" />}
                                    </div>
                                    <div className="flex-1 pb-4">
                                        <h3 className="font-semibold text-gray-900">{phase.phase}</h3>
                                        <p className="text-sm text-gray-500 mb-2">{phase.duration}</p>
                                        {phase.activities && (
                                            <ul className="list-disc list-inside text-sm text-gray-600 space-y-1">
                                                {phase.activities.map((a, aIdx) => (
                                                    <li key={aIdx}>{a}</li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {/* Projected Results */}
            {data.projected_results && (
                <div className="bg-white rounded-lg shadow-md overflow-hidden">
                    <div className="bg-gradient-to-r from-amber-600 to-amber-700 px-6 py-4">
                        <h2 className="text-lg font-semibold text-white">Projected Results</h2>
                    </div>
                    <div className="p-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {Object.entries(data.projected_results).map(([period, result]) => (
                                <div key={period} className="bg-amber-50 rounded-lg p-5 text-center">
                                    <h3 className="text-sm font-bold text-amber-800 uppercase mb-2">
                                        {period.replace('_', ' ')}
                                    </h3>
                                    <p className="text-sm text-gray-700">{result}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {/* Investment Summary */}
            {data.investment_summary && (
                <div className="bg-white rounded-lg shadow-md overflow-hidden">
                    <div className="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                        <h2 className="text-lg font-semibold text-white">Investment Summary</h2>
                    </div>
                    <div className="p-6">
                        <table className="w-full">
                            <tbody>
                                {Object.entries(data.investment_summary).map(([key, val], idx, arr) => (
                                    <tr key={key} className={`${idx === arr.length - 1 ? 'bg-flame-orange-50 font-bold' : ''} border-b border-gray-100`}>
                                        <td className="py-3 text-gray-700 capitalize">{key.replace(/_/g, ' ')}</td>
                                        <td className="py-3 text-right text-gray-900 font-semibold">
                                            {typeof val === 'number' ? `$${val.toLocaleString()}` : val}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Why Us */}
            {data.why_us?.length > 0 && (
                <div className="bg-white rounded-lg shadow-md overflow-hidden">
                    <div className="bg-gradient-to-r from-flame-orange-600 to-flame-orange-700 px-6 py-4">
                        <h2 className="text-lg font-semibold text-white">Why Spectra Media?</h2>
                    </div>
                    <div className="p-6">
                        <ul className="space-y-3">
                            {data.why_us.map((point, idx) => (
                                <li key={idx} className="flex items-start">
                                    <svg className="w-5 h-5 text-flame-orange-500 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                    </svg>
                                    <span className="text-gray-700">{point}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Show({ proposal }) {
    const [currentData, setCurrentData] = useState(proposal.proposal_data);
    const [currentStatus, setCurrentStatus] = useState(proposal.status);

    // Poll for status updates while generating
    useEffect(() => {
        if (currentStatus !== 'generating') return;

        const interval = setInterval(async () => {
            try {
                const response = await fetch(route('proposals.status', proposal.id));
                const result = await response.json();
                setCurrentStatus(result.status);
                if (result.proposal_data) {
                    setCurrentData(result.proposal_data);
                }
                if (result.status !== 'generating') {
                    clearInterval(interval);
                }
            } catch {
                // Silently retry
            }
        }, 5000);

        return () => clearInterval(interval);
    }, [currentStatus, proposal.id]);

    return (
        <AuthenticatedLayout>
            <Head title={`Proposal — ${proposal.client_name}`} />

            <div className="max-w-5xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <Link
                    href={route('proposals.index')}
                    className="text-flame-orange-600 hover:text-flame-orange-800 text-sm font-medium inline-flex items-center mb-6"
                >
                    <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                    Back to Proposals
                </Link>

                {currentStatus === 'generating' && <GeneratingState />}
                {currentStatus === 'failed' && <FailedState error={proposal.error} />}
                {currentStatus === 'ready' && currentData && <ProposalPreview proposal={proposal} data={currentData} />}
            </div>
        </AuthenticatedLayout>
    );
}
