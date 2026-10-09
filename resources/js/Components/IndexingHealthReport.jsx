export default function IndexingHealthReport({ report }) {
    if (!report) return null;
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <h2 className="text-lg font-semibold text-gray-900">Google indexing health</h2>
            <p className="mt-1 text-sm text-gray-600">{report.checked_pages} of {report.requested_pages} requested pages checked · {new Date(report.checked_at).toLocaleDateString()}</p>
            <p className="mt-2 text-sm text-gray-500">{report.note}</p>
            {report.issues?.length > 0 ? (
                <ul className="mt-4 space-y-3">
                    {report.issues.map((issue, i) => (
                        <li key={i} className={`p-3 rounded-lg text-sm ${issue.severity === 'critical' ? 'bg-red-50' : 'bg-amber-50'}`}>
                            <p className="font-medium text-gray-900">{issue.message}</p>
                            <p className="mt-1 text-gray-600 break-all">{issue.url}</p>
                            <p className="mt-2 text-gray-700">{issue.action}</p>
                        </li>
                    ))}
                </ul>
            ) : <p className="mt-4 text-sm text-green-700">No indexing or canonical issues found in the inspected pages.</p>}
            <details className="mt-4">
                <summary className="cursor-pointer text-sm font-medium text-gray-700">Page evidence</summary>
                <ul className="mt-3 space-y-3 text-sm">
                    {report.pages?.map((page, i) => (
                        <li key={i} className="border-t border-gray-100 pt-3">
                            <p className="font-medium break-all">{page.url}</p>
                            <p className="text-gray-600">{page.coverage_state || page.error || page.verdict}</p>
                            {page.google_canonical && <p className="text-gray-600 break-all">Google canonical: {page.google_canonical}</p>}
                            {page.user_canonical && <p className="text-gray-600 break-all">Declared canonical: {page.user_canonical}</p>}
                            {page.last_crawl_time && <p className="text-gray-500">Last Google crawl: {new Date(page.last_crawl_time).toLocaleString()}</p>}
                            {page.inspection_link?.startsWith('https://search.google.com/') && <a className="text-brand-dark underline" href={page.inspection_link} target="_blank" rel="noopener noreferrer">Open in Search Console</a>}
                        </li>
                    ))}
                </ul>
            </details>
        </div>
    );
}
