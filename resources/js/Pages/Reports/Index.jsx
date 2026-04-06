import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function MetricBadge({ label, value, prefix = '' }) {
    if (value === null || value === undefined) return null;
    return (
        <span className="inline-flex items-center gap-1 text-xs text-gray-500">
            <span className="font-medium text-gray-700">{prefix}{typeof value === 'number' ? value.toLocaleString(undefined, { maximumFractionDigits: 2 }) : value}</span>
            {label}
        </span>
    );
}

function ReportCard({ report, onDownload }) {
    const periodLabel = report.period === 'monthly' ? 'Monthly Report' : 'Weekly Report';
    const dateRange = `${report.start} — ${report.end}`;
    const generatedAt = new Date(report.generated_at).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
    });

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-sm transition-shadow">
            <div className="flex items-start justify-between">
                <div className="flex-1">
                    <div className="flex items-center gap-2 mb-1">
                        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold ${
                            report.period === 'monthly'
                                ? 'bg-purple-100 text-purple-700'
                                : 'bg-blue-100 text-blue-700'
                        }`}>
                            {periodLabel}
                        </span>
                        <span className="text-xs text-gray-400">{generatedAt}</span>
                    </div>
                    <p className="text-sm text-gray-600 mb-3">{dateRange}</p>
                    <div className="flex flex-wrap gap-4">
                        <MetricBadge label="spend" value={report.summary?.total_cost} prefix="$" />
                        <MetricBadge label="clicks" value={report.summary?.total_clicks} />
                        <MetricBadge label="conversions" value={report.summary?.total_conversions} />
                        <MetricBadge label="CPA" value={report.summary?.blended_cpa} prefix="$" />
                    </div>
                </div>
                {report.pdf_path && (
                    <button
                        onClick={() => onDownload(report)}
                        className="flex-shrink-0 ml-4 inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-flame-orange-700 bg-flame-orange-50 rounded-lg hover:bg-flame-orange-100 transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        PDF
                    </button>
                )}
            </div>
        </div>
    );
}

export default function Index({ reports = [], customer, canWhiteLabel }) {
    const [generating, setGenerating] = useState(false);

    const handleGenerate = (period) => {
        setGenerating(true);
        router.post(route('reports.generate'), { period }, {
            preserveScroll: true,
            onFinish: () => setGenerating(false),
        });
    };

    const handleDownload = (report) => {
        const date = report.end || report.start;
        window.location.href = route('reports.download', { period: report.period, date });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Reports" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Performance Reports</h1>
                            <p className="mt-1 text-sm text-gray-500">
                                Auto-generated weekly and monthly reports with AI-powered insights.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={() => handleGenerate('weekly')}
                                disabled={generating}
                                className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-50"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Generate Weekly
                            </button>
                            <button
                                onClick={() => handleGenerate('monthly')}
                                disabled={generating}
                                className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors disabled:opacity-50"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Generate Monthly
                            </button>
                        </div>
                    </div>

                    {/* White-label notice */}
                    {canWhiteLabel && (
                        <div className="mb-6 bg-purple-50 border border-purple-200 rounded-lg p-4">
                            <div className="flex items-center gap-2">
                                <svg className="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                                </svg>
                                <span className="text-sm font-medium text-purple-800">Agency Plan — White-label reports available.</span>
                                <a href={route('reports.settings')} className="ml-auto text-sm font-medium text-purple-700 hover:text-purple-900 underline">
                                    Configure Branding →
                                </a>
                            </div>
                        </div>
                    )}

                    {/* Report List */}
                    {reports.length > 0 ? (
                        <div className="space-y-3">
                            {reports.map((report, index) => (
                                <ReportCard key={index} report={report} onDownload={handleDownload} />
                            ))}
                        </div>
                    ) : (
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <svg className="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <h3 className="mt-4 text-sm font-medium text-gray-900">No reports yet</h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Reports are generated automatically every week. You can also generate one now.
                            </p>
                            <div className="mt-6">
                                <button
                                    onClick={() => handleGenerate('weekly')}
                                    disabled={generating}
                                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors disabled:opacity-50"
                                >
                                    Generate First Report
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Info footer */}
                    <div className="mt-8 text-center text-xs text-gray-400">
                        Weekly reports are automatically generated every Monday at 7:00 AM.
                        Monthly reports are generated on the 1st of each month.
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
