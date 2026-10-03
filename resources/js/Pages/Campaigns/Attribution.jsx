import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AttributionReport from '@/Components/AttributionReport';
import AttributionSetup from '@/Components/AttributionSetup';
import GoogleConversionSummary from '@/Components/GoogleConversionSummary';
import { Head, Link } from '@inertiajs/react';

export default function Attribution({ campaign, summary, channelBreakdown, recentTouchpoints, conversions, trackingSetup, googleSummary }) {
    return (
        <AuthenticatedLayout>
            <Head title={`Attribution — ${campaign.name}`} />

            <div className="max-w-7xl mx-auto">
                {/* Header */}
                <div className="mb-8">
                    <Link
                        href={route('campaigns.show', campaign.uuid)}
                        className="text-brand-dark hover:text-brand-darker text-sm font-medium inline-flex items-center mb-3"
                    >
                        <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                        Back to Campaign
                    </Link>
                    <h1 className="text-3xl font-bold text-gray-900">Website attribution</h1>
                    <p className="text-gray-500 mt-1">
                        Compare Google Ads reported conversions with separately collected website events for <span className="font-medium text-gray-700">{campaign.name}</span>. This page does not change bidding.
                    </p>
                </div>

                <GoogleConversionSummary summary={googleSummary} />
                <AttributionSetup setup={trackingSetup} />

                <AttributionReport
                    summary={summary}
                    channelBreakdown={channelBreakdown}
                    recentTouchpoints={recentTouchpoints}
                    conversions={conversions}
                    emptyState={
                        <>
                            <p className="mt-2 text-sm text-gray-500 max-w-md mx-auto">
                                No website touchpoints or conversions have been recorded for this campaign.
                                Install the website tag above to build this journey. Google Ads conversions are reported separately and do not appear in this website journey automatically.
                            </p>
                        </>
                    }
                />
            </div>
        </AuthenticatedLayout>
    );
}
