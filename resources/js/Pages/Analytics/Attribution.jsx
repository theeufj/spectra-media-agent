import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AttributionReport from '@/Components/AttributionReport';
import AttributionSetup from '@/Components/AttributionSetup';
import GoogleConversionSummary from '@/Components/GoogleConversionSummary';
import { Head } from '@inertiajs/react';

export default function Attribution({ summary, channelBreakdown, recentTouchpoints, conversions, trackingSetup, googleSummary }) {
    return (
        <AuthenticatedLayout>
            <Head title="Attribution Models" />
            <div className="py-8">
                <div className="mx-auto max-w-7xl">
                    {/*
                        Said "Back to Analytics" and pointed at analytics.index,
                        which is a redirect to the dashboard — the user landed
                        somewhere other than the word on the link. This is the
                        only page left under /analytics, so the way back is the
                        dashboard and the link now says so.
                    */}
                    <a href={route('dashboard')} className="text-sm text-brand-dark hover:underline mb-1 inline-block">&larr; Back to dashboard</a>
                    <h1 className="text-2xl font-bold text-gray-900 mb-1">Website attribution</h1>
                    <p className="text-sm text-gray-500 mb-6">Compare Google Ads reported conversions with separately collected website events. These sources are not merged or used to change bidding here.</p>

                    <GoogleConversionSummary summary={googleSummary} />
                    <AttributionSetup setup={trackingSetup} />

                    <AttributionReport
                        summary={summary}
                        channelBreakdown={channelBreakdown}
                        recentTouchpoints={recentTouchpoints}
                        conversions={conversions}
                        emptyState={
                            <p className="mt-2 text-sm text-gray-500 max-w-md mx-auto">
                                No website events have been recorded for this account. Install the website tag above to build this journey. Google Ads conversions are reported separately and do not appear in this website journey automatically.
                            </p>
                        }
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
