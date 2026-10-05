import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import ConfirmationModal from '@/Components/ConfirmationModal';
import FormErrorSummary from '@/Components/FormErrorSummary';
import { DialogTitle } from '@headlessui/react';

function ListEditor({ list, onClose }) {
    const { data, setData, post, put, transform, processing, errors } = useForm({ name: list?.name ?? '', keywords: (list?.keywords ?? []).join('\n') });
    const save = event => {
        event.preventDefault();
        transform(values => ({ ...values, keywords: [...new Set(values.keywords.split('\n').map(value => value.trim()).filter(Boolean))] }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (list) put(route('keywords.negative-lists.update', list.id), options);
        else post(route('keywords.negative-lists.store'), options);
    };
    return (
        <Modal show onClose={onClose} closeable={!processing} maxWidth="xl">
            <form onSubmit={save} className="space-y-4 p-6">
                <DialogTitle className="text-lg font-semibold text-gray-900">{list ? 'Edit negative keyword list' : 'New negative keyword list'}</DialogTitle>
                <FormErrorSummary errors={errors} labels={{ name: 'List name', keywords: 'Keywords' }} />
                <div>
                    <label htmlFor="name" className="mb-1 block text-sm font-medium text-gray-700">List name</label>
                    <input id="name" value={data.name} onChange={event => setData('name', event.target.value)} maxLength={100} required autoFocus className="w-full rounded-lg border-gray-300 text-sm" />
                </div>
                <div>
                    <label htmlFor="keywords" className="mb-1 block text-sm font-medium text-gray-700">Keywords, one per line</label>
                    <textarea id="keywords" value={data.keywords} onChange={event => setData('keywords', event.target.value)} rows={8} required className="w-full rounded-lg border-gray-300 text-sm" />
                    <p className="mt-1 text-xs text-gray-500">Duplicate terms are removed when saving.</p>
                </div>
                <div className="flex justify-end gap-3">
                    <button type="button" onClick={onClose} disabled={processing} className="rounded-lg border px-4 py-2 text-sm">Cancel</button>
                    <button disabled={processing} className="rounded-lg bg-brand-dark px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{processing ? 'Saving…' : 'Save list'}</button>
                </div>
            </form>
        </Modal>
    );
}

export default function NegativeLists({ lists = [] }) {
    const [editor, setEditor] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const destroy = () => new Promise((resolve, reject) => router.delete(route('keywords.negative-lists.destroy', deleting.id), {
        preserveScroll: true, onSuccess: resolve, onError: errors => reject(new Error(Object.values(errors).join(' ') || 'The list could not be deleted.')),
    }));
    return (
        <AuthenticatedLayout>
            <Head title="Negative Keyword Lists" />
            <div className="mx-auto max-w-5xl py-8">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Negative Keyword Lists</h1>
                        <p className="mt-1 text-sm text-gray-500">Keep reusable lists of searches to exclude. Saving a list does not change live ads.</p>
                    </div>
                    <button onClick={() => setEditor({ list: null })} className="rounded-lg bg-brand-dark px-4 py-2 text-sm font-medium text-white">New list</button>
                </div>
                {lists.length ? <div className="space-y-4">{lists.map(list => (
                    <div key={list.id} className="rounded-lg border border-gray-200 bg-white p-5">
                        <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                            <div><h2 className="text-sm font-semibold text-gray-900">{list.name}</h2><p className="mt-1 text-xs text-gray-500">{list.keywords?.length ?? 0} keywords · {list.applied_to_campaigns?.length ?? 0} campaign records</p></div>
                            <div className="flex gap-2">
                                <button onClick={() => setEditor({ list })} aria-label={`Edit ${list.name}`} className="rounded border px-3 py-1 text-sm text-gray-700">Edit</button>
                                <button onClick={() => setDeleting(list)} aria-label={`Delete ${list.name}`} className="rounded border border-red-200 px-3 py-1 text-sm text-red-700">Delete</button>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-1">{list.keywords?.slice(0, 15).map((keyword, index) => <span key={index} className="rounded border border-red-100 bg-red-50 px-2 py-1 text-xs text-red-700">{keyword}</span>)}</div>
                        {list.keywords?.length > 15 && <details className="mt-3 text-xs text-gray-600"><summary className="cursor-pointer">Show all {list.keywords.length} keywords</summary><p className="mt-2 whitespace-pre-wrap">{list.keywords.join('\n')}</p></details>}
                    </div>
                ))}</div> : <div className="rounded-lg border bg-white py-16 text-center"><h2 className="text-sm font-medium">No negative keyword lists yet</h2><p className="mt-1 text-sm text-gray-500">Start with terms that bring irrelevant visitors to your business.</p></div>}
            </div>
            {editor && <ListEditor list={editor.list} onClose={() => setEditor(null)} />}
            <ConfirmationModal show={Boolean(deleting)} onClose={() => setDeleting(null)} onConfirm={destroy} title="Delete negative keyword list?" message={`Remove “${deleting?.name ?? ''}” from your saved lists? This does not remove exclusions already applied to live ads.`} confirmText="Delete list" isDestructive />
        </AuthenticatedLayout>
    );
}
