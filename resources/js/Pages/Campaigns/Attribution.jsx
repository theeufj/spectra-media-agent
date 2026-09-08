import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AttributionReport from '@/Components/AttributionReport';
import { Head, Link } from '@inertiajs/react';

export default function Attribution({ campaign, pixelConfig, summary, channelBreakdown, recentTouchpoints, conversions }) {
    return (
        <AuthenticatedLayout>
            <Head title={`Attribution — ${campaign.name}`} />

            <div className="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="mb-8">
                    <Link
                        href={route('campaigns.show', campaign.id)}
                        className="text-brand-dark hover:text-brand-darker text-sm font-medium inline-flex items-center mb-3"
                    >
                        <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                        Back to Campaign
                    </Link>
                    <h1 className="text-3xl font-bold text-gray-900">Multi-Touch Attribution</h1>
                    <p className="text-gray-500 mt-1">
                        Understand how each channel contributes to conversions for <span className="font-medium text-gray-700">{campaign.name}</span>
                    </p>
                </div>

                <AttributionReport
                    summary={summary}
                    channelBreakdown={channelBreakdown}
                    recentTouchpoints={recentTouchpoints}
                    conversions={conversions}
                    emptyState={
                        <>
                            <p className="mt-2 text-sm text-gray-500 max-w-md mx-auto">
                                Install the tracking pixel on your website to start tracking touchpoints and conversions.
                                Attribution data will appear here once visitors begin interacting with your campaigns.
                            </p>
                            <div className="mt-6 bg-gray-50 rounded-lg p-4 max-w-lg mx-auto text-left">
                                <p className="text-sm font-medium text-gray-700 mb-2">Add this snippet before &lt;/body&gt;:</p>
                                <pre className="text-xs bg-gray-900 text-green-400 rounded p-3 overflow-x-auto">
{`<script src="${window.location.origin}/js/spectra-pixel.js"
        data-customer="${pixelConfig.customer_id}"
        data-secret="${pixelConfig.signing_secret}"
        defer></script>`}
                                </pre>
                            </div>
                        </>
                    }
                />
            </div>
        </AuthenticatedLayout>
    );
}
