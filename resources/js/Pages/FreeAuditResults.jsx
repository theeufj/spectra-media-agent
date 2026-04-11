import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

const severityColors = {
    critical: { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-700', badge: 'bg-red-100 text-red-800' },
    high: { bg: 'bg-orange-50', border: 'border-orange-200', text: 'text-orange-700', badge: 'bg-orange-100 text-orange-800' },
    medium: { bg: 'bg-yellow-50', border: 'border-yellow-200', text: 'text-yellow-700', badge: 'bg-yellow-100 text-yellow-800' },
    low: { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-700', badge: 'bg-blue-100 text-blue-800' },
};

function ScoreGauge({ score }) {
    const color = score >= 80 ? 'text-green-500' : score >= 50 ? 'text-yellow-500' : 'text-red-500';
    const label = score >= 80 ? 'Healthy' : score >= 50 ? 'Needs Work' : 'Critical Issues';
    const circumference = 2 * Math.PI * 54;
    const offset = circumference - (score / 100) * circumference;

    return (
        <div className="flex flex-col items-center">
            <div className="relative w-40 h-40">
                <svg className="w-40 h-40 transform -rotate-90" viewBox="0 0 120 120">
                    <circle cx="60" cy="60" r="54" fill="none" stroke="#e5e7eb" strokeWidth="8" />
                    <circle
                        cx="60" cy="60" r="54" fill="none"
                        stroke="currentColor"
                        strokeWidth="8"
                        strokeLinecap="round"
                        strokeDasharray={circumference}
                        strokeDashoffset={offset}
                        className={`${color} transition-all duration-1000 ease-out`}
                    />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className={`text-4xl font-bold ${color}`}>{score}</span>
                    <span className="text-xs text-gray-500">/ 100</span>
                </div>
            </div>
            <p className={`mt-3 font-semibold ${color}`}>{label}</p>
        </div>
    );
}

function LoadingState() {
    return (
        <div className="flex flex-col items-center justify-center py-20">
            <div className="relative w-20 h-20">
                <div className="absolute inset-0 rounded-full border-4 border-flame-orange-200 animate-ping opacity-25"></div>
                <div className="relative w-20 h-20 rounded-full border-4 border-flame-orange-500 border-t-transparent animate-spin"></div>
            </div>
            <h2 className="mt-8 text-2xl font-bold text-gray-900">Analyzing your account...</h2>
            <p className="mt-2 text-gray-500">Our AI is scanning campaigns, ads, keywords, and more.</p>
            <div className="mt-6 flex gap-2">
                {['Campaigns', 'Ad Copy', 'Keywords', 'Extensions', 'Conversions'].map((item, i) => (
                    <span key={i} className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-flame-orange-100 text-flame-orange-800 animate-pulse" style={{ animationDelay: `${i * 200}ms` }}>
                        {item}
                    </span>
                ))}
            </div>
        </div>
    );
}

export default function FreeAuditResults({ auth, audit }) {
    const [currentAudit, setCurrentAudit] = useState(audit);

    // Poll for completion if still running
    useEffect(() => {
        if (currentAudit.status === 'pending' || currentAudit.status === 'running') {
            const interval = setInterval(async () => {
                try {
                    const res = await fetch(`/api/audit/${currentAudit.token}/status`);
                    const data = await res.json();
                    if (data.status === 'completed' || data.status === 'failed') {
                        setCurrentAudit(prev => ({
                            ...prev,
                            status: data.status,
                            score: data.score,
                            results: data.results,
                        }));
                        clearInterval(interval);
                    }
                } catch (e) {
                    // silently retry
                }
            }, 3000);
            return () => clearInterval(interval);
        }
    }, [currentAudit.status, currentAudit.token]);

    const isLoading = currentAudit.status === 'pending' || currentAudit.status === 'running';
    const isFailed = currentAudit.status === 'failed';
    const results = currentAudit.results;
    const findings = results?.findings || [];
    const recommendations = results?.recommendations || [];
    const summary = results?.summary || {};

    return (
        <>
            <Head title="Your Ad Account Audit - sitetospend" />
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main className="pb-20">
                    {isLoading ? (
                        <LoadingState />
                    ) : isFailed ? (
                        <div className="max-w-2xl mx-auto mt-16 px-6 text-center">
                            <div className="text-5xl">😔</div>
                            <h2 className="mt-4 text-2xl font-bold text-gray-900">Audit Failed</h2>
                            <p className="mt-2 text-gray-500">We couldn't complete the audit. This may be due to API access issues.</p>
                            <a href="/free-audit" className="mt-6 inline-flex items-center px-6 py-3 bg-flame-orange-600 text-white font-medium rounded-lg hover:bg-flame-orange-700 transition-colors">
                                Try Again
                            </a>
                        </div>
                    ) : (
                        <>
                            {/* Score Header */}
                            <div className="bg-white border-b border-gray-200 py-12">
                                <div className="max-w-5xl mx-auto px-6 flex flex-col md:flex-row items-center gap-8">
                                    <ScoreGauge score={currentAudit.score || 0} />
                                    <div className="text-center md:text-left">
                                        <h1 className="text-3xl font-bold text-gray-900">Your {currentAudit.platform === 'google' ? 'Google Ads' : 'Facebook Ads'} Audit</h1>
                                        <p className="mt-2 text-gray-500">
                                            We found <strong className="text-gray-900">{summary.total_findings || 0} issue{(summary.total_findings || 0) !== 1 ? 's' : ''}</strong> in your account
                                            {summary.estimated_wasted_spend > 0 && (
                                                <>, with an estimated <strong className="text-red-600">${summary.estimated_wasted_spend?.toLocaleString()}</strong> in wasted spend over the last 30 days</>
                                            )}.
                                        </p>
                                        <div className="mt-4 flex gap-3 justify-center md:justify-start">
                                            {summary.critical > 0 && <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{summary.critical} Critical</span>}
                                            {summary.high > 0 && <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">{summary.high} High</span>}
                                            {summary.medium > 0 && <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">{summary.medium} Medium</span>}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="max-w-5xl mx-auto px-6 mt-10 grid grid-cols-1 lg:grid-cols-3 gap-8">
                                {/* Findings Column */}
                                <div className="lg:col-span-2 space-y-4">
                                    <h2 className="text-xl font-bold text-gray-900">Findings</h2>
                                    {findings.length === 0 ? (
                                        <div className="bg-green-50 border border-green-200 rounded-lg p-6 text-center">
                                            <span className="text-3xl">🎉</span>
                                            <p className="mt-2 text-green-700 font-medium">Your account looks great! No major issues found.</p>
                                        </div>
                                    ) : (
                                        findings.map((finding, i) => {
                                            const colors = severityColors[finding.severity] || severityColors.low;
                                            return (
                                                <div key={i} className={`${colors.bg} ${colors.border} border rounded-lg p-4`}>
                                                    <div className="flex items-start gap-3">
                                                        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold uppercase ${colors.badge}`}>
                                                            {finding.severity}
                                                        </span>
                                                        <div>
                                                            <h3 className={`font-semibold ${colors.text}`}>{finding.title}</h3>
                                                            <p className="mt-1 text-sm text-gray-600">{finding.description}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })
                                    )}
                                </div>

                                {/* Recommendations Sidebar */}
                                <div className="space-y-6">
                                    {/* CTA Card */}
                                    <div className="bg-flame-orange-600 rounded-xl p-6 text-white">
                                        <h3 className="text-lg font-bold">Let Site to Spend Fix These Automatically</h3>
                                        <p className="mt-2 text-sm text-flame-orange-100">
                                            Our AI agents can deploy optimized campaigns, fix policy violations, and manage your budget 24/7.
                                        </p>
                                        <a
                                            href={`/register?audit=${currentAudit.token}`}
                                            className="mt-4 w-full inline-flex items-center justify-center px-4 py-3 bg-white text-flame-orange-600 font-semibold rounded-lg hover:bg-flame-orange-50 transition-colors"
                                        >
                                            Sign Up Free
                                        </a>
                                        <p className="mt-2 text-xs text-flame-orange-200 text-center">No credit card required</p>
                                    </div>

                                    {/* Recommendations */}
                                    {recommendations.length > 0 && (
                                        <div>
                                            <h2 className="text-xl font-bold text-gray-900">Top Recommendations</h2>
                                            <div className="mt-4 space-y-3">
                                                {recommendations.map((rec, i) => (
                                                    <div key={i} className="bg-white border border-gray-200 rounded-lg p-4">
                                                        <div className="flex items-center gap-2">
                                                            <span className="flex-shrink-0 w-6 h-6 rounded-full bg-flame-orange-100 text-flame-orange-700 text-xs font-bold flex items-center justify-center">
                                                                {i + 1}
                                                            </span>
                                                            <h3 className="font-semibold text-gray-900 text-sm">{rec.title}</h3>
                                                        </div>
                                                        <p className="mt-2 text-xs text-gray-500">{rec.description}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </main>

                <Footer />
            </div>
        </>
    );
}
