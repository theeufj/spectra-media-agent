import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AttributionReport from '@/Components/AttributionReport';
import { Head } from '@inertiajs/react';

export default function Attribution({ summary, channelBreakdown, recentTouchpoints, conversions }) {
    return (
        <AuthenticatedLayout>
            <Head title="Attribution Models" />
            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <a href={route('analytics.index')} className="text-sm text-brand-dark hover:underline mb-1 inline-block">&larr; Back to Analytics</a>
                    <h1 className="text-2xl font-bold text-gray-900 mb-1">Multi-Touch Attribution</h1>
                    <p className="text-sm text-gray-500 mb-6">Cross-campaign attribution analysis — see how each channel contributes to conversions.</p>

                    <AttributionReport
                        summary={summary}
                        channelBreakdown={channelBreakdown}
                        recentTouchpoints={recentTouchpoints}
                        conversions={conversions}
                        emptyState={
                            <p className="mt-2 text-sm text-gray-500 max-w-md mx-auto">
                                Install the tracking pixel on your website to start collecting attribution data.
                                Conversion data will appear here once visitors begin converting.
                            </p>
                        }
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
