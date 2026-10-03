import { useCurrency } from '@/hooks/useCurrency';
import { money } from '@/utils/format';

export default function GoogleConversionSummary({ summary }) {
    const currency = useCurrency();
    if (!summary) return null;

    return (
        <section className="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-5" aria-label="Google Ads reported conversions">
            <h2 className="font-semibold text-blue-950">Google Ads reported conversions · last 30 days</h2>
            {summary.latest_date ? (
                <p className="mt-1 text-sm text-blue-900">
                    {summary.conversions} conversions · {money(summary.conversion_value ?? 0, currency)} conversion value. Latest synced day: {summary.latest_date}.
                </p>
            ) : (
                <p className="mt-1 text-sm text-blue-900">No Google Ads performance data has been synced for the past 30 days.</p>
            )}
            <p className="mt-2 text-xs text-blue-800">These are Google Ads reporting totals, shown separately from the website events below. They may differ because the sources and attribution rules differ. This page does not change Google Ads goals or bidding.</p>
        </section>
    );
}
