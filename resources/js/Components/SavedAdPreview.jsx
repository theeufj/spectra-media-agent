export default function SavedAdPreview({ preview }) {
    if (!preview) return null;

    const { url, ad_copy: { headlines, descriptions } } = preview;

    return (
        <section aria-label="Saved Google Search ad" className="rounded-xl border border-gray-200 bg-white p-5 text-left sm:p-6">
            <h2 className="text-lg font-semibold text-gray-900">Your saved Google Search ad</h2>
            <p className="mt-1 text-sm text-gray-600">Draft from your website preview. Review and approve your campaign ads before launch.</p>
            <div className="mt-4 rounded-lg border border-gray-200 p-4">
                <p className="break-words text-xs text-gray-500">Ad · {url}</p>
                <p className="mt-1 text-lg font-medium text-blue-800">{headlines.join(' | ')}</p>
                <p className="mt-1 text-sm text-gray-700">{descriptions.join(' ')}</p>
            </div>
        </section>
    );
}
