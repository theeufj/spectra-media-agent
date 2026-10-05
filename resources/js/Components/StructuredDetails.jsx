const labelFor = key => key.replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/^./, letter => letter.toUpperCase());

/** Readable supporting facts for AI suggestions and agent activity. */
export default function StructuredDetails({ value }) {
    if (value === null || value === undefined) return null;
    if (typeof value === 'boolean') return <span>{value ? 'Yes' : 'No'}</span>;
    if (typeof value !== 'object') return <span className="whitespace-pre-wrap break-words">{String(value)}</span>;
    if (Array.isArray(value)) return <ul className="list-inside list-disc space-y-1">{value.map((entry, index) => <li key={index}><StructuredDetails value={entry} /></li>)}</ul>;
    const entries = Object.entries(value).filter(([key, entry]) => entry !== null && entry !== undefined && !/(token|api_key|resource_name|customer_id|account_id)/i.test(key));
    return <dl className="space-y-2">{entries.map(([key, entry]) => <div key={key}><dt className="font-medium text-gray-700">{labelFor(key)}</dt><dd className="mt-0.5 text-gray-600"><StructuredDetails value={entry} /></dd></div>)}</dl>;
}
