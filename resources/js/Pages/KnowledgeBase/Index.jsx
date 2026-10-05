import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { usePolling } from '@/hooks/usePolling';
import { fetchJson } from '@/utils/http';
import FormErrorSummary from '@/Components/FormErrorSummary';

const states = { queued: 'Queued', reading: 'Reading source', indexing: 'Preparing for AI', ready: 'Ready for AI', needs_attention: 'Preparation needs attention', failed: 'Could not read source' };
export function SourceStatus({ source }) {
    const state = source.excluded_at ? 'Excluded from AI' : states[source.processing_status] || 'Needs review';
    const color = source.excluded_at ? 'bg-gray-100 text-gray-600' : source.processing_status === 'ready' ? 'bg-green-50 text-green-800' : ['failed', 'needs_attention'].includes(source.processing_status) ? 'bg-amber-50 text-amber-900' : 'bg-blue-50 text-blue-800';
    return <span className={`inline-flex rounded-full px-3 py-1 text-xs font-medium ${color}`}>{state}</span>;
}
function ImportPreview({ run, remaining }) {
    const candidates = run.candidates || [];
    const max = Math.min(100, remaining ?? 100);
    const form = useForm({ urls: candidates.filter(p => p.recommended).slice(0, max).map(p => p.url) });
    const toggle = url => form.setData('urls', form.data.urls.includes(url) ? form.data.urls.filter(x => x !== url) : [...form.data.urls, url]);
    return <section className="bg-white rounded-xl border p-5 space-y-4" aria-label="Choose website pages">
        <h3 className="font-semibold text-lg">Choose pages from {run.website_url}</h3>
        <p className="text-sm text-gray-600">Prioritise offers, pricing, services, products, and your business story. Only selected pages will be read. Up to {max} {max === 1 ? 'page' : 'pages'} can be included in this import.</p>
        <FormErrorSummary errors={form.errors} />
        <div className="flex flex-wrap gap-3 text-sm"><button type="button" onClick={() => form.setData('urls', candidates.filter(p => p.recommended).slice(0, max).map(p => p.url))} className="underline min-h-[44px]">Select recommended pages</button><button type="button" onClick={() => form.setData('urls', [])} className="underline min-h-[44px]">Clear selection</button><span className="py-3" role="status">{form.data.urls.length} selected</span></div>
        <div className="max-h-80 overflow-y-auto border rounded-lg divide-y">{candidates.map(page => <label key={page.url} className="flex gap-3 items-start p-3 cursor-pointer"><input type="checkbox" checked={form.data.urls.includes(page.url)} disabled={!form.data.urls.includes(page.url) && form.data.urls.length >= max} onChange={() => toggle(page.url)} className="mt-1 rounded text-brand-primary" /><span className="min-w-0"><span className="block font-medium">{page.title}{page.recommended && <span className="text-xs text-green-700 ml-2">Recommended</span>}</span><span className="block text-xs text-gray-500 break-all">{page.url}</span></span></label>)}</div>
        <button type="button" disabled={form.processing || form.data.urls.length === 0} onClick={() => form.post(route('knowledge-base.imports.start', run.id))} className="bg-brand-dark text-white px-4 py-3 rounded-lg disabled:opacity-50">{form.processing ? 'Starting…' : 'Read selected pages'}</button>
    </section>;
}
export default function Index({ knowledgeBases, customer, health: initialHealth = {}, imports: initialImports = [], filters = {}, sourceLimit, brandProfile }) {
    const [question, setQuestion] = useState('');
    const [results, setResults] = useState(null);
    const [searchError, setSearchError] = useState('');
    const [searching, setSearching] = useState(false);
    const [watch, setWatch] = useState(true);
    const [filterText, setFilterText] = useState(filters.q || '');
    const [filterStatus, setFilterStatus] = useState(filters.status || '');
    const searchSequence = useRef(0);
    const mounted = useRef(true);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
    useEffect(() => { searchSequence.current += 1; setResults(null); setQuestion(''); setSearchError(''); setSearching(false); }, [customer?.id]);
    const busy = initialHealth.pending > 0 || initialHealth.needs_attention > 0 || initialImports.some(r => ['queued', 'discovering', 'processing'].includes(r.status));
    const poll = usePolling(`${route('knowledge-base.status')}?customer_context=${customer?.id || ''}`, { enabled: busy && watch, interval: 8000 });
    useEffect(() => { if (poll.failureStreak >= 3) setWatch(false); }, [poll.failureStreak]);
    const health = poll.data?.health || initialHealth;
    const imports = poll.data?.imports || initialImports;
    const reloading = useRef(false);
    useEffect(() => {
        if (!poll.data || reloading.current) return;
        reloading.current = true;
        router.reload({ only: ['knowledgeBases', 'health', 'imports', 'brandProfile'], preserveScroll: true, onFinish: () => { reloading.current = false; } });
    }, [poll.data]);
    useEffect(() => {
        if (!busy || !watch) return;
        const timer = setTimeout(() => setWatch(false), 10 * 60 * 1000);
        return () => clearTimeout(timer);
    }, [busy, watch]);
    const search = async e => {
        e.preventDefault(); const sequence = ++searchSequence.current;
        setSearching(true); setSearchError(''); setResults(null);
        try {
            const response = await fetchJson(route('knowledge-base.search'), { method: 'POST', json: { query: question } });
            if (mounted.current && sequence === searchSequence.current) setResults(response.results);
        } catch { if (mounted.current && sequence === searchSequence.current) setSearchError('We could not search right now. Your sources are retained. Try again.'); }
        finally { if (mounted.current && sequence === searchSequence.current) setSearching(false); }
    };
    const applyFilters = e => { e.preventDefault(); router.get(route('knowledge-base.index'), { q: filterText, status: filterStatus }, { preserveState: true, preserveScroll: true }); };
    const sources = knowledgeBases?.data || [];
    return <AuthenticatedLayout header={<div className="flex flex-wrap gap-3 items-center justify-between"><h2 className="font-semibold text-xl">Business knowledge</h2><Link href={route('knowledge-base.create')} className="rounded-lg bg-brand-dark text-white px-4 py-3 min-h-[44px]">Add information</Link></div>}>
        <Head title="Business knowledge" />
        <div className="max-w-7xl mx-auto py-8 space-y-6">
            <div><h1 className="text-2xl font-semibold">What the AI knows about {customer?.name}</h1><p className="mt-2 text-gray-600">Choose sources, review your business profile, and test the evidence available for campaign generation. Changes become profile proposals for you to review; existing ads keep their approved content.</p></div>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3" aria-label="Knowledge readiness">{[['Included sources', health.selected || 0], ['Ready for AI', health.ready || 0], ['Reading or preparing', health.pending || 0], ['Need attention', (health.failed || 0) + (health.needs_attention || 0)]].map(([label, value]) => <div key={label} className="bg-white border rounded-xl p-4"><p className="text-sm text-gray-500">{label}</p><p className="text-2xl font-semibold mt-1">{value}</p></div>)}</div>
            {sourceLimit != null && <p className="text-sm text-gray-600">Your plan includes {sourceLimit} active sources. A website page counts as one source. <Link href={route('subscription.pricing')} className="underline">View plans</Link></p>}
            {((poll.failureStreak || 0) >= 3 || !watch) && <div role="alert" className="bg-amber-50 border rounded-lg p-4">Status updates are paused. Background work can continue. <button className="underline ml-2" onClick={() => { setWatch(true); router.reload({ only: ['knowledgeBases', 'health', 'imports'] }); }}>Check status again</button></div>}
            {imports.map(run => run.status === 'review' ? <ImportPreview key={run.id} run={run} remaining={sourceLimit == null ? null : Math.max(0, sourceLimit - (health.selected || 0))} /> : ['queued', 'discovering', 'processing', 'failed'].includes(run.status) && <div key={run.id} role={run.status === 'failed' ? 'alert' : 'status'} className="rounded-lg border p-4 bg-white"><p className="font-medium break-all">{run.website_url}</p><p className="text-sm mt-1 text-gray-600">{run.status === 'failed' ? run.error || 'Reading finished with failures. Review the affected sources below.' : run.status === 'processing' ? `Reading ${run.selected_urls?.length || 0} selected pages. Source statuses below show what is usable.` : 'Finding public pages. You will choose what to include before reading starts.'}</p>{run.status === 'failed' && <Link className="underline text-sm" href={route('knowledge-base.create')}>Try another address or add a note</Link>}</div>)}
            <section className="rounded-xl border bg-white p-5 flex flex-col sm:flex-row gap-4 sm:items-center sm:justify-between"><div><h2 className="font-semibold">Review business understanding</h2><p className="text-sm text-gray-600 mt-1">{brandProfile ? 'Check offers, audiences, brand voice, and restrictions. Human corrections are kept during refreshes.' : 'Your business profile will be proposed after readable information is available.'}</p></div><Link href={route('brand-guidelines.index', { review: 1 })} className="text-brand-primary font-medium underline whitespace-nowrap">Review business profile</Link></section>
            {(health.readable || 0) > 0 && <section className="bg-white border rounded-xl p-5 space-y-4"><h2 className="font-semibold text-lg">Test your knowledge</h2><p className="text-sm text-gray-600">Ask a question to see supporting passages available to the AI. These are source excerpts, not a confidence score or a generated answer.</p><form onSubmit={search} className="flex flex-col sm:flex-row gap-3"><label className="sr-only" htmlFor="knowledge-question">Business question</label><input id="knowledge-question" required maxLength={1000} value={question} onChange={e => setQuestion(e.target.value)} placeholder="What is our refund policy?" className="flex-1 min-w-0 rounded-lg border-gray-300" /><button disabled={searching || !question.trim()} className="px-5 py-3 bg-brand-dark text-white rounded-lg disabled:opacity-50">{searching ? 'Finding evidence…' : 'Find evidence'}</button></form><div className="flex flex-wrap gap-2">{['What services do we offer?', 'Who are our customers?', 'What claims should ads avoid?'].map(q => <button key={q} className="text-sm underline min-h-[44px]" onClick={() => setQuestion(q)}>{q}</button>)}</div>{searchError && <p role="alert" className="text-red-700">{searchError}</p>}{results && <div aria-live="polite">{results.length === 0 ? <div className="bg-amber-50 p-4 rounded-lg"><p>No supporting passage found. Add the missing information, check excluded sources, or try a more specific question.</p><Link href={route('knowledge-base.create')} className="underline">Add missing information</Link></div> : <><p className="text-sm mb-3">Found {results.length} supporting passages.</p><div className="space-y-3">{results.map(result => <article key={`${result.kb_id || result.url}-${result.position}`} className="border rounded-lg p-4"><p className="text-sm font-semibold break-words">{result.source_name}</p><p className="text-xs text-gray-500 mt-1">Version {result.source_version} · Passage {result.position + 1}</p><p className="whitespace-pre-wrap mt-3 text-sm leading-relaxed">{result.chunk}</p>{result.kb_id ? <Link className="inline-block text-sm underline text-brand-primary mt-3 min-h-[44px]" href={route('knowledge-base.show', { knowledgeBase: result.kb_id, version: result.source_version })}>View source and context</Link> : <a href={result.url} target="_blank" rel="noopener noreferrer" className="inline-block underline mt-3 text-sm">Open original page</a>}</article>)}</div></>}</div>}</section>}
            <section className="space-y-4"><h2 className="font-semibold text-lg">Sources</h2><form onSubmit={applyFilters} className="flex flex-col sm:flex-row gap-3"><label className="sr-only" htmlFor="source-filter">Find a source</label><input id="source-filter" value={filterText} onChange={e => setFilterText(e.target.value)} placeholder="Find by title or address" className="rounded-lg border-gray-300 flex-1 min-w-0" /><label className="sr-only" htmlFor="source-status">Source status</label><select id="source-status" value={filterStatus} onChange={e => setFilterStatus(e.target.value)} className="rounded-lg border-gray-300"><option value="">All sources</option><option value="ready">Ready for AI</option><option value="pending">Reading or preparing</option><option value="needs_attention">Preparation needs attention</option><option value="failed">Could not read</option><option value="excluded">Excluded</option></select><button className="border rounded-lg px-4 py-3">Filter sources</button></form>
                {sources.length ? <div className="space-y-3">{sources.map(source => <article key={source.id} className="bg-white rounded-xl border p-5 flex flex-col sm:flex-row gap-4 sm:items-center"><div className="min-w-0 flex-1"><Link href={route('knowledge-base.show', source.id)} className="font-semibold text-brand-primary break-words underline">{source.title || source.original_filename || source.url}</Link><p className="text-xs text-gray-500 mt-1 break-all">{source.source_type === 'text' ? 'Text' : source.source_type === 'pdf' ? 'PDF' : 'Website'} · Version {source.source_version}{source.source_type === 'url' && ` · ${source.url}`}</p>{source.preview && <p className="text-sm text-gray-600 mt-3 line-clamp-2">{source.preview}</p>}{source.processing_error && !source.excluded_at && <p className="text-sm text-amber-800 mt-2">{source.processing_error}</p>}<p className="text-xs text-gray-500 mt-2">{source.fetched_at ? `Last read ${new Date(source.fetched_at).toLocaleString()}` : 'Awaiting first read'}</p></div><div className="flex flex-wrap gap-3 items-center"><SourceStatus source={source} /><Link className="underline text-sm min-h-[44px] inline-flex items-center" href={route('knowledge-base.show', source.id)}>Review source</Link></div></article>)}</div> : <div className="bg-white border rounded-xl p-8 text-center"><h3 className="font-semibold">{health.total ? 'No sources match these filters' : 'Add the information your ads should be based on'}</h3><p className="mt-2 text-gray-600">Your website, a product document, or written business facts can help the AI create specific, accurate campaigns.</p><Link className="inline-block bg-brand-dark text-white px-5 py-3 rounded-lg mt-4" href={route('knowledge-base.create')}>Add information</Link></div>}
                {knowledgeBases?.last_page > 1 && <nav aria-label="Source pages" className="flex flex-wrap items-center justify-between gap-3"><span className="text-sm text-gray-500">Page {knowledgeBases.current_page} of {knowledgeBases.last_page} · {knowledgeBases.total} sources</span><div className="flex gap-3">{knowledgeBases.prev_page_url && <Link href={knowledgeBases.prev_page_url} className="border rounded-lg px-4 py-3">Previous</Link>}{knowledgeBases.next_page_url && <Link href={knowledgeBases.next_page_url} className="border rounded-lg px-4 py-3">Next</Link>}</div></nav>}
            </section>
        </div>
    </AuthenticatedLayout>;
}
