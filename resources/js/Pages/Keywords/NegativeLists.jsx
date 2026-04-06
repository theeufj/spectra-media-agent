import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

function ListCard({ list, onEdit }) {
    const keywordCount = list.keywords?.length || 0;
    const campaignCount = list.applied_to_campaigns?.length || 0;

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <div className="flex items-start justify-between mb-3">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900">{list.name}</h3>
                    <p className="text-xs text-gray-500 mt-0.5">{keywordCount} keywords · {campaignCount} campaigns</p>
                </div>
                <div className="flex gap-1">
                    <button onClick={() => onEdit(list)} className="text-xs px-2 py-1 text-gray-500 hover:text-gray-700 hover:bg-gray-50 rounded">Edit</button>
                    <button onClick={() => { if (confirm('Delete this list?')) router.delete(route('keywords.negative-lists.destroy', list.id), { preserveScroll: true }); }} className="text-xs px-2 py-1 text-red-500 hover:text-red-700 hover:bg-red-50 rounded">Delete</button>
                </div>
            </div>
            <div className="flex flex-wrap gap-1">
                {list.keywords?.slice(0, 15).map((kw, i) => (
                    <span key={i} className="text-xs px-2 py-0.5 bg-red-50 text-red-600 rounded border border-red-100">{kw}</span>
                ))}
                {keywordCount > 15 && <span className="text-xs text-gray-400">+{keywordCount - 15} more</span>}
            </div>
        </div>
    );
}

export default function NegativeLists({ lists = [] }) {
    const [showCreate, setShowCreate] = useState(false);
    const [form, setForm] = useState({ name: '', keywordsText: '' });
    const [saving, setSaving] = useState(false);

    const handleCreate = (e) => {
        e.preventDefault();
        setSaving(true);
        const keywords = form.keywordsText.split('\n').map(k => k.trim()).filter(Boolean);
        router.post(route('keywords.negative-lists.store'), { name: form.name, keywords }, {
            preserveScroll: true,
            onSuccess: () => { setShowCreate(false); setForm({ name: '', keywordsText: '' }); },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Negative Keyword Lists" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Negative Keyword Lists</h1>
                            <p className="mt-1 text-sm text-gray-500">Shared negative keyword lists to apply across campaigns.</p>
                        </div>
                        <button onClick={() => setShowCreate(!showCreate)} className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700">
                            New List
                        </button>
                    </div>

                    {showCreate && (
                        <form onSubmit={handleCreate} className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <div className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">List Name</label>
                                    <input type="text" value={form.name} onChange={e => setForm({...form, name: e.target.value})} required placeholder="e.g. Universal Negatives" className="w-full rounded-lg border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Keywords (one per line)</label>
                                    <textarea value={form.keywordsText} onChange={e => setForm({...form, keywordsText: e.target.value})} rows={6} required placeholder={"free\ncheap\njobs\nsalary\nreddit"} className="w-full rounded-lg border-gray-300 text-sm font-mono" />
                                </div>
                                <div className="flex justify-end gap-2">
                                    <button type="button" onClick={() => setShowCreate(false)} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                                    <button type="submit" disabled={saving} className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 disabled:opacity-50">
                                        {saving ? 'Creating...' : 'Create List'}
                                    </button>
                                </div>
                            </div>
                        </form>
                    )}

                    {lists.length > 0 ? (
                        <div className="space-y-4">
                            {lists.map(list => <ListCard key={list.id} list={list} onEdit={() => {}} />)}
                        </div>
                    ) : (
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <h3 className="text-sm font-medium text-gray-900">No negative keyword lists</h3>
                            <p className="mt-1 text-sm text-gray-500">Create shared lists to block wasted spend across all campaigns.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
