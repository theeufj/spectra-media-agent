import React from 'react';
import { router } from '@inertiajs/react';
import { fetchJson } from '@/utils/http';

/**
 * Find a customer, user or campaign from anywhere in the admin console.
 *
 * Searches on the identifiers support questions actually arrive with — an email
 * address, a domain, a campaign id copied out of the Google Ads console — rather
 * than only on display names.
 */
const TYPE_LABELS = {
    customer: 'Customer',
    user: 'User',
    campaign: 'Campaign',
};

export default function AdminSearch() {
    const [term, setTerm] = React.useState('');
    const [results, setResults] = React.useState([]);
    const [open, setOpen] = React.useState(false);
    const [loading, setLoading] = React.useState(false);
    const containerRef = React.useRef(null);

    React.useEffect(() => {
        if (term.trim().length < 2) {
            setResults([]);
            return undefined;
        }

        // Debounced: a request per keystroke would spend the admin rate limit on
        // prefixes nobody wanted results for.
        const timer = setTimeout(async () => {
            setLoading(true);
            try {
                const data = await fetchJson(`${route('admin.search')}?q=${encodeURIComponent(term)}`);
                setResults(data.results || []);
                setOpen(true);
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 250);

        return () => clearTimeout(timer);
    }, [term]);

    React.useEffect(() => {
        const onClickAway = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onClickAway);
        return () => document.removeEventListener('mousedown', onClickAway);
    }, []);

    const go = (url) => {
        setOpen(false);
        setTerm('');
        router.visit(url);
    };

    return (
        <div className="relative px-4 pb-2" ref={containerRef}>
            <label htmlFor="admin-search" className="sr-only">
                Search customers, users and campaigns
            </label>
            <input
                id="admin-search"
                type="search"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                onFocus={() => results.length > 0 && setOpen(true)}
                onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                placeholder="Search email, domain, campaign id…"
                className="w-full rounded-md border-gray-300 text-sm focus:border-brand-primary focus:ring-brand-primary"
            />

            {open && (
                <div className="absolute left-4 right-4 z-20 mt-1 max-h-96 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg">
                    {loading && <p className="px-3 py-2 text-sm text-gray-500">Searching…</p>}

                    {!loading && results.length === 0 && (
                        <p className="px-3 py-2 text-sm text-gray-500">No matches for “{term}”.</p>
                    )}

                    {results.map((result) => (
                        <button
                            key={`${result.type}-${result.id}`}
                            type="button"
                            onClick={() => go(result.url)}
                            className="block w-full px-3 py-2 text-left hover:bg-gray-50 focus:bg-gray-50 focus:outline-none"
                        >
                            <span className="mr-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600">
                                {TYPE_LABELS[result.type] || result.type}
                            </span>
                            <span className="text-sm font-medium text-gray-900">{result.title}</span>
                            <span className="block text-xs text-gray-500">{result.subtitle}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
