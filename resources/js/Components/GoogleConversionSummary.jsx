import { useCurrency } from '@/hooks/useCurrency';
import { money } from '@/utils/format';

export default function GoogleConversionSummary({ summary }) {
    const currency = useCurrency();
    if (!summary) return null;
    const hasRecentData = summary.latest_date && Date.now() - new Date(`${summary.latest_date}T00:00:00Z`).getTime() <= 3 * 86400000;

    return (
        <section className="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-5" aria-label="Google Ads reported conversions">
            <h2 className="font-semibold text-blue-950">Google Ads reported conversions · last 30 days</h2>
            {summary.latest_date ? (
                <p className="mt-1 text-sm text-blue-900">
                    {summary.conversions} conversions · {money(summary.conversion_value ?? 0, currency)} conversion value. Latest stored performance day: {summary.latest_date}.
                </p>
            ) : (
                <p className="mt-1 text-sm text-blue-900">No Google Ads performance data has been synced for the past 30 days.</p>
            )}
            {summary.latest_date && !hasRecentData && (
                <p className="mt-2 text-sm font-medium text-amber-900">Recent days have no stored Google Ads performance row. These totals are incomplete as a current status check.</p>
            )}
            <p className="mt-2 text-xs text-blue-800">These are Google Ads reporting totals, shown separately from the website events below. They may differ because the sources and attribution rules differ. This page does not change Google Ads goals or bidding.</p>
        </section>
    );
}
