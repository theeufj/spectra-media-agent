import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmationModal from '@/Components/ConfirmationModal';
import FormErrorSummary from '@/Components/FormErrorSummary';
import { SourceStatus } from './Index';

export default function Show({ source, customer, passages = [], version, versions = [], retrievals = [], editableNote, fileRevision, errors = {} }) {
    const form = useForm({ title: source.title || source.original_filename || source.url, content: source.content || '', source_version: source.source_version });
    const replacement = useForm({ document: null, source_version: source.source_version });
    const [editing, setEditing] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [pollPaused, setPollPaused] = useState(false);
    const isCurrent = version === source.source_version;
    const pending = ['queued', 'reading', 'indexing', 'needs_attention'].includes(source.processing_status) && !source.excluded_at;
    const reloading = useRef(false);
    useEffect(() => {
        if (!pending || pollPaused) return;
        const timer = setInterval(() => {
            if (reloading.current) return;
            reloading.current = true;
            router.reload({ only: ['source', 'passages', 'version', 'versions', 'retrievals', 'fileRevision'], preserveScroll: true, onFinish: () => { reloading.current = false; } });
        }, 8000);
        const deadline = setTimeout(() => setPollPaused(true), 10 * 60 * 1000);
        return () => { clearInterval(timer); clearTimeout(deadline); };
    }, [pending, pollPaused]);
    useEffect(() => {
        if (!editing) form.setData({ title: source.title || source.original_filename || source.url, content: source.content || '', source_version: source.source_version });
    }, [source.source_version, source.content, source.title, editing]);
    const save = e => { e.preventDefault(); form.transform(data => editableNote ? data : { title: data.title, source_version: data.source_version }).put(route('knowledge-base.update', source.id), { onSuccess: () => setEditing(false) }); };
    const [actionBusy, setActionBusy] = useState(false);
    const action = (method, name, data) => { if (actionBusy) return; setActionBusy(true); router[method](route(name, source.id), data, { preserveScroll: true, onFinish: () => setActionBusy(false) }); };
    const deleteSource = () => new Promise((resolve, reject) => router.delete(route('knowledge-base.destroy', source.id), { onSuccess: resolve, onError: () => reject(new Error('Deletion did not finish. Try again.')), onCancel: () => reject(new Error('Deletion was cancelled.')) }));
    const include = () => action('put', 'knowledge-base.update', { included: !!source.excluded_at, source_version: source.source_version });
    const historicalText = passages.map(p => p.content).join('\n\n');
    return <AuthenticatedLayout header={<h2 className="font-semibold text-xl">Review knowledge source</h2>}>
        <Head title={source.title || source.original_filename || 'Knowledge source'} />
        <div className="max-w-5xl mx-auto py-8 space-y-6">
            <Link href={route('knowledge-base.index')} className="text-brand-primary underline text-sm">Back to business knowledge</Link>
            <FormErrorSummary errors={errors} /><div><p className="text-sm text-gray-500">{customer?.name} · Source version {version}</p><h1 className="text-2xl font-semibold mt-1 break-words">{source.title || source.original_filename || source.url}</h1><div className="mt-3 flex flex-wrap gap-3 items-center"><SourceStatus source={source} />{source.source_type === 'url' && <a href={source.url} target="_blank" rel="noopener noreferrer" className="underline text-sm break-all">Open website page</a>}<span className="text-sm text-gray-500">{source.fetched_at ? `Last read ${new Date(source.fetched_at).toLocaleString()}` : 'Awaiting first read'}</span></div></div>
            {source.processing_error && <div role="alert" className="border bg-amber-50 rounded-lg p-4">{source.processing_error}</div>}
            {pollPaused && pending && <p role="status" className="bg-blue-50 rounded-lg p-4">Live updates paused. Work can continue in the background. <button className="underline" onClick={() => setPollPaused(false)}>Check status again</button></p>}
            {!isCurrent && <div className="bg-blue-50 rounded-lg p-4">This is a historical version. <Link href={route('knowledge-base.show', source.id)} className="underline">Review the current version</Link></div>}
            <div className="flex flex-wrap gap-3">{isCurrent && <><button disabled={actionBusy} onClick={include} className="border rounded-lg px-4 py-3 bg-white">{source.excluded_at ? 'Include for AI' : 'Exclude from AI'}</button><button disabled={actionBusy || !!source.excluded_at} onClick={() => action('post', 'knowledge-base.retry', {})} className="border rounded-lg px-4 py-3 bg-white disabled:opacity-50">{source.source_type === 'text' && editableNote ? 'Prepare for AI again' : 'Refresh source'}</button><button onClick={() => setEditing(true)} className="border rounded-lg px-4 py-3 bg-white">{editableNote ? 'Edit note' : 'Rename source'}</button><button onClick={() => setConfirmDelete(true)} className="text-red-700 underline min-h-[44px]">Delete source</button></>}{versions.length > 1 && <div><label htmlFor="source-version" className="sr-only">Source history</label><select id="source-version" className="border-gray-300 rounded-lg min-h-[44px]" value={version} onChange={e => router.get(route('knowledge-base.show', source.id), { version: Number(e.target.value) })}>{versions.map(v => <option key={v} value={v}>Version {v}{v === source.source_version ? ' (current)' : ''}</option>)}</select></div>}</div>
            <p className="text-sm text-gray-600">Included sources can support future generation. Profile changes require your review. Existing campaign ads keep their approved content until you deliberately regenerate and approve them.</p>
            {editing ? <form onSubmit={save} className="bg-white border rounded-xl p-5 space-y-4"><FormErrorSummary errors={form.errors} /><div><label htmlFor="source-title" className="block font-medium mb-2">Source title</label><input id="source-title" className="rounded-lg border-gray-300 w-full" required maxLength={255} value={form.data.title} onChange={e => form.setData('title', e.target.value)} /></div>{editableNote && <div><label htmlFor="source-content" className="block font-medium mb-2">Business information</label><textarea id="source-content" required maxLength={100000} rows={14} className="rounded-lg border-gray-300 w-full" value={form.data.content} onChange={e => form.setData('content', e.target.value)} /></div>}<div className="flex gap-3"><button disabled={form.processing} className="bg-brand-dark text-white rounded-lg px-4 py-3 disabled:opacity-50">{form.processing ? 'Saving…' : 'Save source changes'}</button><button type="button" className="border rounded-lg px-4 py-3" onClick={() => { form.reset(); form.clearErrors(); setEditing(false); }}>Cancel</button></div></form> : <section className="border rounded-xl bg-white p-5"><h2 className="font-semibold text-lg">{isCurrent ? 'Readable source content' : `Content from version ${version}`}</h2>{(isCurrent ? source.content : historicalText) ? <div className="whitespace-pre-wrap text-sm leading-relaxed mt-4 break-words">{isCurrent ? source.content : historicalText}</div> : <p className="text-gray-500 mt-3">Readable text is not available yet. Check the source status above or add the information as a note.</p>}</section>}
            {isCurrent && !editableNote && source.source_type !== 'url' && <form onSubmit={e => { e.preventDefault(); replacement.transform(data => ({ ...data, source_version: source.source_version, file_revision: fileRevision })).post(route('knowledge-base.replace', source.id), { onSuccess: () => replacement.reset() }); }} className="bg-white border rounded-xl p-5 space-y-3"><h2 className="font-semibold">Replace document</h2><p className="text-sm text-gray-600">Upload a revised PDF or text file, up to 10 MB. Previous readable passages remain in source history.</p><FormErrorSummary errors={replacement.errors} /><label htmlFor="replacement-document" className="block text-sm font-medium">Replacement file</label><input id="replacement-document" type="file" accept=".pdf,.txt" onChange={e => replacement.setData('document', e.target.files[0] || null)} className="block w-full text-sm" /><button disabled={replacement.processing || !replacement.data.document} className="border rounded-lg px-4 py-3 disabled:opacity-50">{replacement.processing ? 'Uploading…' : 'Upload replacement'}</button></form>}
            <section className="bg-white border rounded-xl p-5 space-y-3"><h2 className="font-semibold text-lg">Evidence retrieved for generation</h2><p className="text-sm text-gray-600">This records passages supplied to generation. Retrieval alone does not establish that a claim appeared in a finished ad.</p>{retrievals.length ? retrievals.map(item => <article key={item.id} className="border rounded-lg p-3"><p className="text-sm">{item.purpose === 'first_campaign' ? 'First campaign brief' : 'Campaign strategy'} · Version {item.source_version} · {new Date(item.retrieved_at).toLocaleString()}</p><p className="text-sm mt-2">Question: {item.query}</p><div className="flex flex-wrap gap-3 mt-3"><Link href={route('knowledge-base.show', { knowledgeBase: source.id, version: item.source_version })} className="text-sm underline">Review evidence version</Link>{item.campaign_id && <Link href={route('campaigns.show', item.campaign_id)} className="text-sm underline">Open campaign</Link>}</div></article>) : <p className="text-sm text-gray-500">No generation retrieval has been recorded for this source yet.</p>}</section>
        </div>
        <ConfirmationModal show={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={deleteSource} title="Delete knowledge source" message={`Delete “${source.title || source.original_filename || source.url}” and its evidence history? Excluding it keeps history and removes it from future AI retrieval.`} confirmText="Delete source" isDestructive />
    </AuthenticatedLayout>;
}
