export default function AttributionSetup({ setup }) {
    if (!setup) return null;

    return (
        <section className="mb-8 rounded-xl border border-gray-200 bg-white p-6 shadow-sm" aria-label="Website tracking setup">
            <h2 className="text-lg font-semibold text-gray-900">Website tracking setup</h2>
            {setup.website_host ? (
                <>
                    <p className="mt-2 text-sm text-gray-600">
                        Install this on <strong>{setup.website_host}</strong> to collect website visits for this report. Add it after the consent step required by your site. This tag sends events to SiteToSpend; it does not configure Google Ads conversions or bidding.
                    </p>
                    <pre className="mt-4 overflow-x-auto rounded-lg bg-gray-900 p-4 text-sm text-white"><code>{setup.snippet}</code></pre>
                    <p className="mt-3 text-sm text-gray-600">
                        Add the script once on each page, or add it as a GTM Custom HTML tag firing on All Pages. When a lead or sale succeeds, call <code className="rounded bg-gray-100 px-1">window.SpectraPixel.trackConversion(&apos;lead&apos;, 0)</code> from that success event. For a sale, use <code className="rounded bg-gray-100 px-1">trackConversion(&apos;purchase&apos;, amount)</code> with the actual amount. Do not fire it on page load unless that page can only be reached after a completed conversion.
                    </p>
                    <div className="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-sm">
                        <p><strong>Latest visit:</strong> {setup.last_visit_at ? new Date(setup.last_visit_at).toLocaleString() : 'No events received yet'}</p>
                        <p><strong>Latest conversion:</strong> {setup.last_conversion_at ? new Date(setup.last_conversion_at).toLocaleString() : 'No events received yet'}</p>
                    </div>
                    <p className="mt-3 text-xs text-gray-500">These are browser-reported events, not verified sales. Check your site&apos;s consent rules before installing. Refresh this page after visiting your site to check the latest event.</p>
                </>
            ) : (
                <p className="mt-2 text-sm text-gray-600">Add your business website to your account before installing website tracking.</p>
            )}
        </section>
    );
}
