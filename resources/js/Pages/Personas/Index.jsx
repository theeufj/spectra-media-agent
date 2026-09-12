import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { UsersIcon, SparklesIcon } from '@heroicons/react/24/outline';

function PersonaCard({ persona, onToggle, onDelete }) {
    const tone = persona.tone_adjustments || {};
    const demo = persona.demographics || {};

    return (
        <div className={`bg-white rounded-lg border ${persona.is_active ? 'border-gray-200' : 'border-gray-100 opacity-60'} p-5`}>
            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-2">
                    <h3 className="text-sm font-semibold text-gray-900">{persona.name}</h3>
                    <span className={`text-xs px-2 py-0.5 rounded ${persona.source === 'ai_generated' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'}`}>
                        {persona.source === 'ai_generated' ? 'AI' : 'Manual'}
                    </span>
                </div>
                <div className="flex items-center gap-1">
                    <button onClick={() => onToggle(persona)} className={`text-xs px-2 py-1 rounded ${persona.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                        {persona.is_active ? 'Active' : 'Inactive'}
                    </button>
                    <button onClick={() => onDelete(persona)} className="text-xs px-2 py-1 text-red-500 hover:bg-red-50 rounded">Delete</button>
                </div>
            </div>

            <p className="text-sm text-gray-600 mb-3">{persona.description}</p>

            {demo.age_range && (
                <div className="flex flex-wrap gap-2 mb-3">
                    {demo.age_range && <span className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">{demo.age_range}</span>}
                    {demo.income_level && <span className="text-xs bg-green-50 text-green-700 px-2 py-0.5 rounded">{demo.income_level}</span>}
                    {demo.location_type && <span className="text-xs bg-amber-50 text-amber-700 px-2 py-0.5 rounded">{demo.location_type}</span>}
                </div>
            )}

            {persona.pain_points?.length > 0 && (
                <div className="mb-3">
                    <p className="text-xs font-medium text-gray-500 mb-1">Pain Points</p>
                    <ul className="space-y-0.5">
                        {persona.pain_points.map((p, i) => <li key={i} className="text-xs text-gray-600">• {p}</li>)}
                    </ul>
                </div>
            )}

            {persona.messaging_angle && (
                <div className="mb-3">
                    <p className="text-xs font-medium text-gray-500 mb-1">Messaging Angle</p>
                    <p className="text-xs text-gray-600">{persona.messaging_angle}</p>
                </div>
            )}

            {(tone.formality || tone.urgency || tone.emotion) && (
                <div className="flex gap-2">
                    {tone.formality && <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{tone.formality}</span>}
                    {tone.urgency && <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{tone.urgency} urgency</span>}
                    {tone.emotion && <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{tone.emotion}</span>}
                </div>
            )}
        </div>
    );
}

export default function Index({ personas, campaigns }) {
    const [showCreate, setShowCreate] = useState(false);
    const generateForm = useForm({ campaign_id: '', count: 4 });
    const createForm = useForm({
        name: '', description: '', messaging_angle: '', campaign_id: '',
        pain_points: [''], tone_adjustments: { formality: 'balanced', urgency: 'medium', emotion: 'balanced' },
    });

    // Called both as a form onSubmit and straight from the empty-state button,
    // which has no event to cancel.
    const handleGenerate = (e) => {
        e?.preventDefault?.();
        generateForm.post(route('personas.generate'), { preserveScroll: true });
    };

    const handleCreate = (e) => {
        e.preventDefault();
        const data = { ...createForm.data, pain_points: createForm.data.pain_points.filter(p => p.trim()) };
        createForm.transform(() => data).post(route('personas.store'), {
            preserveScroll: true,
            onSuccess: () => { setShowCreate(false); createForm.reset(); },
        });
    };

    const handleToggle = (persona) => {
        router.put(route('personas.update', persona.id), { is_active: !persona.is_active }, { preserveScroll: true });
    };

    const handleDelete = (persona) => {
        if (confirm(`Delete "${persona.name}"?`)) {
            router.delete(route('personas.destroy', persona.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Audience Personas" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Audience Personas</h1>
                            <p className="mt-1 text-sm text-gray-500">AI-generated audience segments to tailor ad copy and targeting.</p>
                        </div>
                        <button onClick={() => setShowCreate(!showCreate)} className="px-4 py-2 text-sm font-medium text-white bg-brand-dark rounded-lg hover:bg-brand-darker">
                            {showCreate ? 'Cancel' : '+ Create Persona'}
                        </button>
                    </div>

                    {/* AI Generate Panel */}
                    <div className="bg-purple-50 border border-purple-200 rounded-lg p-5 mb-6">
                        <h3 className="text-sm font-semibold text-purple-900 mb-3">Generate with AI</h3>
                        <form onSubmit={handleGenerate} className="flex items-end gap-4">
                            <div className="flex-1">
                                <label className="block text-xs text-purple-700 mb-1">Campaign (optional)</label>
                                <select value={generateForm.data.campaign_id} onChange={e => generateForm.setData('campaign_id', e.target.value)} className="w-full rounded-lg border-purple-200 text-sm">
                                    <option value="">All campaigns</option>
                                    {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-purple-700 mb-1">Count</label>
                                <select value={generateForm.data.count} onChange={e => generateForm.setData('count', parseInt(e.target.value))} className="rounded-lg border-purple-200 text-sm">
                                    {[2, 3, 4, 5, 6].map(n => <option key={n} value={n}>{n}</option>)}
                                </select>
                            </div>
                            <button type="submit" disabled={generateForm.processing} className="px-4 py-2 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700 disabled:opacity-50">
                                {generateForm.processing ? 'Generating...' : 'Generate Personas'}
                            </button>
                        </form>
                    </div>

                    {/* Manual Create Form */}
                    {showCreate && (
                        <form onSubmit={handleCreate} className="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                            <h3 className="text-sm font-semibold text-gray-900 mb-4">Create Persona Manually</h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label className="block text-xs text-gray-600 mb-1">Name</label>
                                    <input type="text" value={createForm.data.name} onChange={e => createForm.setData('name', e.target.value)} placeholder="e.g. Budget-Conscious Buyer" className="w-full rounded-lg border-gray-300 text-sm" required />
                                </div>
                                <div>
                                    <label className="block text-xs text-gray-600 mb-1">Campaign</label>
                                    <select value={createForm.data.campaign_id} onChange={e => createForm.setData('campaign_id', e.target.value)} className="w-full rounded-lg border-gray-300 text-sm">
                                        <option value="">None</option>
                                        {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                    </select>
                                </div>
                            </div>
                            <div className="mb-4">
                                <label className="block text-xs text-gray-600 mb-1">Description</label>
                                <textarea value={createForm.data.description} onChange={e => createForm.setData('description', e.target.value)} rows={2} className="w-full rounded-lg border-gray-300 text-sm" required />
                            </div>
                            <div className="mb-4">
                                <label className="block text-xs text-gray-600 mb-1">Messaging Angle</label>
                                <input type="text" value={createForm.data.messaging_angle} onChange={e => createForm.setData('messaging_angle', e.target.value)} placeholder="e.g. Emphasize value and ROI" className="w-full rounded-lg border-gray-300 text-sm" />
                            </div>
                            <div className="mb-4">
                                <label className="block text-xs text-gray-600 mb-1">Pain Points</label>
                                {createForm.data.pain_points.map((point, i) => (
                                    <div key={i} className="flex gap-2 mb-1">
                                        <input type="text" value={point} onChange={e => {
                                            const pts = [...createForm.data.pain_points];
                                            pts[i] = e.target.value;
                                            createForm.setData('pain_points', pts);
                                        }} className="flex-1 rounded-lg border-gray-300 text-sm" placeholder="Pain point..." />
                                        {i === createForm.data.pain_points.length - 1 && (
                                            <button type="button" onClick={() => createForm.setData('pain_points', [...createForm.data.pain_points, ''])} className="text-xs text-gray-500 hover:text-gray-700">+ Add</button>
                                        )}
                                    </div>
                                ))}
                            </div>
                            <div className="grid grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label className="block text-xs text-gray-600 mb-1">Formality</label>
                                    <select value={createForm.data.tone_adjustments.formality} onChange={e => createForm.setData('tone_adjustments', { ...createForm.data.tone_adjustments, formality: e.target.value })} className="w-full rounded-lg border-gray-300 text-sm">
                                        <option value="casual">Casual</option>
                                        <option value="balanced">Balanced</option>
                                        <option value="formal">Formal</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs text-gray-600 mb-1">Urgency</label>
                                    <select value={createForm.data.tone_adjustments.urgency} onChange={e => createForm.setData('tone_adjustments', { ...createForm.data.tone_adjustments, urgency: e.target.value })} className="w-full rounded-lg border-gray-300 text-sm">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs text-gray-600 mb-1">Emotion</label>
                                    <select value={createForm.data.tone_adjustments.emotion} onChange={e => createForm.setData('tone_adjustments', { ...createForm.data.tone_adjustments, emotion: e.target.value })} className="w-full rounded-lg border-gray-300 text-sm">
                                        <option value="rational">Rational</option>
                                        <option value="balanced">Balanced</option>
                                        <option value="emotional">Emotional</option>
                                    </select>
                                </div>
                            </div>
                            <div className="flex justify-end">
                                <button type="submit" disabled={createForm.processing} className="px-4 py-2 text-sm font-medium text-white bg-brand-dark rounded-lg hover:bg-brand-darker disabled:opacity-50">
                                    {createForm.processing ? 'Creating...' : 'Create Persona'}
                                </button>
                            </div>
                        </form>
                    )}

                    {/* Persona Cards */}
                    {personas.length === 0 ? (
                        /*
                         * Said "Generate some with AI or create manually" and
                         * then offered neither — both controls are above it,
                         * one of them inside a panel the empty state does not
                         * point at. An empty state that names an action should
                         * be the thing you click. It also now says what a
                         * persona is for, because the page's own subtitle is
                         * the only other place that explains it and a first-time
                         * visitor is reading this box, not the subtitle.
                         */
                        <div className="rounded-lg border border-gray-200 bg-white px-6 py-12 text-center">
                            <UsersIcon className="mx-auto h-12 w-12 text-gray-300" aria-hidden="true" />
                            <h2 className="mt-4 text-sm font-medium text-gray-900">No personas yet</h2>
                            <p className="mx-auto mt-1 max-w-md text-sm text-gray-600">
                                A persona is who a set of ads is written for — their pain points and the
                                tone that lands with them. Campaigns use them to vary ad copy instead of
                                showing everyone the same words.
                            </p>
                            <div className="mt-6 flex flex-col items-center justify-center gap-2 sm:flex-row">
                                <button
                                    type="button"
                                    onClick={handleGenerate}
                                    disabled={generateForm.processing}
                                    className="inline-flex min-h-[44px] items-center gap-1.5 rounded-lg bg-brand-dark px-4 text-sm font-medium text-white transition-colors hover:bg-brand-darker disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-600"
                                >
                                    <SparklesIcon className="h-4 w-4" aria-hidden="true" />
                                    {generateForm.processing ? 'Generating…' : 'Generate 4 with AI'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowCreate(true)}
                                    className="inline-flex min-h-[44px] items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                                >
                                    Write one myself
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {personas.map(p => (
                                <PersonaCard key={p.id} persona={p} onToggle={handleToggle} onDelete={handleDelete} />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
