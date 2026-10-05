import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormErrorSummary from '@/Components/FormErrorSummary';
import InputError from '@/Components/InputError';

export default function Create({ customer, sourceLimit, sourceCount = 0 }) {
    const [dropError, setDropError] = useState('');
    const form = useForm({ mode: 'website', website_url: customer?.website || '', page_url: '', document: null, title: '', content: '' });
    const { data, setData, errors, processing, progress } = form;
    const modes = [['website', 'Website', 'Discover pages and choose what to include'], ['page', 'One page', 'Read a specific public page'], ['document', 'Document', 'PDF or text, up to 10 MB'], ['note', 'Write a note', 'Offers, restrictions, prices, or business facts']];
    const setFile = (file) => {
        if (!file) return;
        if (!/\.(pdf|txt)$/i.test(file.name)) { setDropError('Choose a PDF or text file.'); return; }
        if (file.size > 10 * 1024 * 1024) { setDropError('This file exceeds 10 MB. Choose a smaller file.'); return; }
        setDropError(''); setData('document', file);
    };
    const submit = (event) => { event.preventDefault(); form.post(route('knowledge-base.store')); };
    const atLimit = sourceLimit !== null && sourceLimit !== undefined && sourceCount >= sourceLimit;
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Add business knowledge</h2>}>
        <Head title="Add business knowledge" />
        <div className="max-w-3xl mx-auto py-8 space-y-6">
            <Link href={route('knowledge-base.index')} className="text-sm text-brand-primary underline">Back to business knowledge</Link>
            <div><h1 className="text-2xl font-semibold">Add information for {customer?.name}</h1><p className="text-gray-600 mt-2">Give the AI accurate information about your business. You can review what it learns before approving changes to your profile.</p></div>
            {sourceLimit != null && <div className="rounded-lg bg-blue-50 p-4 text-sm">Your plan includes {sourceLimit} active sources; {sourceCount} are included. Each website page counts as one source. {atLimit && <Link href={route('subscription.pricing')} className="underline font-medium">Upgrade or exclude an existing source to add more.</Link>}</div>}
            <div className="grid sm:grid-cols-2 gap-3" role="group" aria-label="Source type">{modes.map(([mode, label, description]) => <button key={mode} type="button" aria-pressed={data.mode === mode} onClick={() => setData('mode', mode)} className={`p-4 rounded-xl border text-left min-h-[80px] focus-visible:ring-2 focus-visible:ring-brand-primary ${data.mode === mode ? 'border-brand-primary bg-orange-50' : 'border-gray-200 bg-white'}`}><span className="block font-semibold">{label}</span><span className="block mt-1 text-sm text-gray-600">{description}</span></button>)}</div>
            <form onSubmit={submit} className="bg-white border rounded-xl p-6 space-y-5">
                <FormErrorSummary errors={errors} />
                {data.mode === 'website' && <div><label htmlFor="website_url" className="block font-medium mb-2">Website address</label><input id="website_url" type="url" required value={data.website_url} onChange={e => setData('website_url', e.target.value)} placeholder="https://yourbusiness.com" className="w-full rounded-lg border-gray-300" aria-invalid={!!errors.website_url} aria-describedby="website-help" /><p id="website-help" className="text-sm text-gray-500 mt-2">We find your sitemap automatically. You can also enter a sitemap address. You will choose pages before reading starts; discovery shows up to 250 pages per import.</p><InputError message={errors.website_url || errors.sitemap_url} /></div>}
                {data.mode === 'page' && <div><label htmlFor="page_url" className="block font-medium mb-2">Public page address</label><input id="page_url" type="url" required value={data.page_url} onChange={e => setData('page_url', e.target.value)} placeholder="https://yourbusiness.com/services" className="w-full rounded-lg border-gray-300" aria-invalid={!!errors.page_url} /><InputError message={errors.page_url} /></div>}
                {data.mode === 'document' && <div onDragOver={e => e.preventDefault()} onDrop={e => { e.preventDefault(); setFile(e.dataTransfer.files[0]); }} className="rounded-xl border-2 border-dashed p-5"><label htmlFor="document" className="block font-medium mb-2">Choose a document or drop it here</label><input id="document" type="file" accept=".pdf,.txt" onChange={e => setFile(e.target.files[0])} className="block w-full text-sm" aria-describedby="document-help" /><p id="document-help" className="mt-3 text-sm text-gray-500">PDF or TXT, up to 10 MB. PDFs need selectable text. For scanned documents, paste the text into a note.</p>{data.document && <p className="mt-3 text-sm">Selected: {data.document.name} ({(data.document.size / 1048576).toFixed(1)} MB) <button type="button" onClick={() => setData('document', null)} className="underline ml-2">Remove file</button></p>}<InputError message={dropError || errors.document} /></div>}
                {data.mode === 'note' && <><div><label htmlFor="title" className="block font-medium mb-2">Note title</label><input id="title" required maxLength={255} value={data.title} onChange={e => setData('title', e.target.value)} placeholder="Current offers and restrictions" className="w-full rounded-lg border-gray-300" /><InputError message={errors.title} /></div><div><label htmlFor="content" className="block font-medium mb-2">Business information</label><textarea id="content" required rows={10} maxLength={100000} value={data.content} onChange={e => setData('content', e.target.value)} placeholder="Describe your products, service areas, pricing, evidence for claims, or anything the ads must avoid." className="w-full rounded-lg border-gray-300" /><InputError message={errors.content} /></div></>}
                {progress && <p role="status" className="text-sm">Uploading {progress.percentage}%</p>}
                <div className="flex justify-end"><button type="submit" disabled={processing || atLimit || (data.mode === 'document' && !data.document)} className="px-5 py-3 min-h-[44px] rounded-lg bg-brand-dark text-white disabled:opacity-50">{processing ? 'Adding source…' : data.mode === 'website' ? 'Find pages to include' : 'Add source'}</button></div>
            </form>
        </div>
    </AuthenticatedLayout>;
}
