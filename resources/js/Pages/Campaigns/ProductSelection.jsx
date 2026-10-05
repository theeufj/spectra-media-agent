import React, { useEffect, useState } from 'react';
import { fetchJson } from '@/utils/http';
import { Combobox, ComboboxInput, ComboboxButton, ComboboxOptions, ComboboxOption } from '@headlessui/react';

export default function ProductSelection({ customerUuid, selectedPages = [], initialPages = [], destinationUrl = '', onSelectionChange }) {
    const [pages, setPages] = useState([]);
    const [loading, setLoading] = useState(false);
    const [query, setQuery] = useState('');
    const [error, setError] = useState(null);
    const [retry, setRetry] = useState(0);
    const [selected, setSelected] = useState(null);
    const selectedId = selectedPages[0];
    useEffect(() => {
        const known = initialPages.find(page => String(page.id) === String(selectedId));
        setSelected(selectedId ? known || { id: selectedId, title: destinationUrl || 'Saved landing page', url: destinationUrl } : null);
        if (!customerUuid || !selectedId || known) return;
        const controller = new AbortController();
        fetchJson(route('api.customers.pages.index', { customer: customerUuid, ids: [selectedId] }), { signal: controller.signal }).then(result => {
            if (!controller.signal.aborted && result.data?.[0]) setSelected(result.data[0]);
        }).catch(failure => { if (failure.name !== 'AbortError') setError('We could not load the saved landing page. Its destination is still kept in your draft.'); });
        return () => controller.abort();
    }, [customerUuid, selectedId, destinationUrl]);
    useEffect(() => {
        if (!customerUuid) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true); setError(null);
            try {
                const result = await fetchJson(route('api.customers.pages.index', { customer: customerUuid, search: query }), { signal: controller.signal });
                if (!controller.signal.aborted) setPages(result.data || []);
            } catch (failure) {
                if (!controller.signal.aborted) setError('We could not load website pages. Retry or keep the saved destination.');
            } finally { if (!controller.signal.aborted) setLoading(false); }
        }, 300);
        return () => { controller.abort(); clearTimeout(timer); };
    }, [customerUuid, query, retry]);
    return <div className="space-y-2">
        <label htmlFor="campaign-destination" className="block text-sm font-medium text-gray-700">Campaign destination</label>
        <Combobox value={selected} by="id" onChange={page => { setSelected(page); onSelectionChange(page ?? null); }}>
            <div className="relative"><ComboboxInput id="campaign-destination" className="w-full rounded-lg border-gray-300 py-3 pr-10 text-sm" displayValue={page => page?.title || page?.url || ''} onChange={event => setQuery(event.target.value)} placeholder="Search included website pages" /><ComboboxButton aria-label="Show landing page choices" className="absolute inset-y-0 right-0 min-w-[44px] px-3">⌄</ComboboxButton><ComboboxOptions className="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-white p-1 shadow-lg ring-1 ring-black/10">{loading ? <p role="status" className="p-3 text-sm">Loading pages…</p> : pages.length ? pages.map(page => <ComboboxOption key={page.id} value={page} className="cursor-pointer rounded p-3 text-sm data-[focus]:bg-brand-tint-10"><span className="block font-medium">{page.title || page.url}</span><span className="block break-all text-xs text-gray-500">{page.url}</span></ComboboxOption>) : <p className="p-3 text-sm text-gray-600">{error ? 'Pages could not be loaded.' : query ? 'No included pages match this search.' : 'No included website pages yet. Add a source in your knowledge base.'}</p>}</ComboboxOptions></div>
        </Combobox>
        {selected && <p className="break-all text-xs text-gray-600">Saved destination: {selected.url || destinationUrl}</p>}
        {error && <div role="alert" className="text-sm text-red-700">{error} <button type="button" className="min-h-[44px] px-2 underline" onClick={() => setRetry(value => value + 1)}>Retry</button></div>}
    </div>;
}
