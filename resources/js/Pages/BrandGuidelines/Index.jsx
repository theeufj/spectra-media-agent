import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { SetupStages } from '@/Components/SetupJourney';
import BrandExtractionStatus from '@/Components/BrandExtractionStatus';
import ConfirmationModal from '@/Components/ConfirmationModal';
import FormErrorSummary from '@/Components/FormErrorSummary';

const PROFILE_FIELDS = ['brand_voice', 'tone_attributes', 'writing_patterns', 'color_palette', 'typography', 'visual_style', 'messaging_themes', 'unique_selling_propositions', 'target_audience', 'competitor_differentiation', 'brand_personality', 'do_not_use', 'service_lines'];
const SECTIONS = [
    { id: 'overview', title: 'Offer & boundaries', fields: [['unique_selling_propositions', 'Reasons to choose your business', 'list'], ['do_not_use', 'Claims, words and approaches to avoid', 'list']] },
    { id: 'voice', title: 'Voice & personality', fields: [['brand_voice.primary_tone', 'Primary tone'], ['brand_voice.description', 'Voice description', 'long'], ['brand_voice.examples', 'Examples from your writing', 'list'], ['tone_attributes', 'Tone attributes', 'list'], ['brand_personality.archetype', 'Personality archetype'], ['brand_personality.characteristics', 'Personality characteristics', 'list'], ['brand_personality.if_brand_were_person', 'If your brand were a person', 'long']] },
    { id: 'writing', title: 'Writing style', fields: [['writing_patterns.sentence_length', 'Sentence length'], ['writing_patterns.paragraph_style', 'Paragraph style', 'long'], ['writing_patterns.uses_questions', 'Uses questions', 'boolean'], ['writing_patterns.uses_statistics', 'Uses statistics', 'boolean'], ['writing_patterns.uses_testimonials', 'Uses testimonials', 'boolean'], ['writing_patterns.uses_storytelling', 'Uses storytelling', 'boolean'], ['writing_patterns.call_to_action_style', 'Calls to action', 'long'], ['writing_patterns.punctuation_style', 'Punctuation'], ['writing_patterns.emoji_usage', 'Emoji use']] },
    { id: 'visual', title: 'Visual identity', fields: [['color_palette.primary_colors', 'Primary colours', 'list'], ['color_palette.secondary_colors', 'Secondary colours', 'list'], ['color_palette.description', 'Colour direction', 'long'], ['color_palette.usage_notes', 'Colour usage', 'long'], ['typography.heading_style', 'Heading style'], ['typography.body_style', 'Body style'], ['typography.fonts_detected', 'Fonts', 'list'], ['typography.font_weights', 'Font weights'], ['typography.letter_spacing', 'Letter spacing'], ['visual_style.overall_aesthetic', 'Overall aesthetic'], ['visual_style.imagery_style', 'Imagery style'], ['visual_style.description', 'Visual direction', 'long'], ['visual_style.color_treatment', 'Colour treatment'], ['visual_style.layout_preference', 'Layout']] },
    { id: 'messaging', title: 'Messaging', fields: [['messaging_themes', 'Messaging themes', 'list'], ['competitor_differentiation', 'How you differ from competitors', 'list']] },
    { id: 'audience', title: 'Audience', fields: [['target_audience.primary', 'Primary audience', 'long'], ['target_audience.demographics', 'Demographics', 'long'], ['target_audience.psychographics', 'Needs and motivations', 'long'], ['target_audience.pain_points', 'Problems you address', 'list'], ['target_audience.language_level', 'Language level'], ['target_audience.familiarity_assumption', 'Audience familiarity']] },
    { id: 'services', title: 'Products & services', fields: [] },
];
const INPUT = 'mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-brand-dark focus:ring-brand-dark';
const ACTION = 'inline-flex min-h-[44px] items-center justify-center rounded-lg bg-brand-dark px-4 py-2 text-sm font-semibold text-white hover:bg-brand-darker disabled:opacity-50';
const SECONDARY = 'inline-flex min-h-[44px] items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50';
const getPath = (object, path) => path.split('.').reduce((value, key) => value?.[key], object);
function profileData(brand) {
    const profile = brand?.proposed_profile || brand || {};
    return { ...Object.fromEntries(PROFILE_FIELDS.map(key => [key, profile[key] ?? (['tone_attributes', 'messaging_themes', 'unique_selling_propositions', 'competitor_differentiation', 'do_not_use', 'service_lines'].includes(key) ? [] : {})])), profile_version: brand?.profile_version ?? 1 };
}
function readable(value) {
    if (value === null || value === undefined || value === '') return 'Not specified';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) return value.map(readable).join(' · ') || 'None';
    if (typeof value === 'object') return Object.entries(value).map(([key, item]) => `${key.replaceAll('_', ' ')}: ${readable(item)}`).join('\n');
    return String(value);
}
function ProfileField({ path, label, type = 'text', data, update, editing, errors }) {
    const value = getPath(data, path);
    const error = errors[path];
    const attrs = { id: path, 'aria-invalid': error ? true : undefined, 'aria-describedby': error ? `${path}-error` : undefined };
    return <div>
        <label htmlFor={editing ? path : undefined} className="block text-sm font-semibold text-gray-800">{label}</label>
        {!editing ? <p className="mt-2 whitespace-pre-wrap break-words text-sm text-gray-700">{readable(value)}</p>
            : type === 'list' ? <div id={path} tabIndex={-1} className="mt-2 space-y-2">
                {(Array.isArray(value) ? value : []).map((item, index) => <div key={index} className="flex items-start gap-2">
                    <input id={`${path}.${index}`} aria-label={`${label} ${index + 1}`} aria-invalid={Boolean(errors[`${path}.${index}`])} aria-describedby={errors[`${path}.${index}`] ? `${path}.${index}-error` : undefined} value={typeof item === 'string' ? item : readable(item)} className={`${INPUT} min-w-0 flex-1`} onChange={event => update(path, value.map((old, i) => i === index ? event.target.value : old))} />
                    <button type="button" aria-label={`Remove ${label.toLowerCase()} ${index + 1}`} className="min-h-[44px] px-3 text-sm text-red-700" onClick={() => update(path, value.filter((_, i) => i !== index))}>Remove</button>
                    {errors[`${path}.${index}`] && <p id={`${path}.${index}-error`} className="text-sm text-red-700">{errors[`${path}.${index}`]}</p>}
                </div>)}
                <button type="button" className="min-h-[44px] text-sm font-medium text-brand-dark underline" onClick={() => update(path, [...(Array.isArray(value) ? value : []), ''])}>Add {label.toLowerCase()}</button>
            </div> : type === 'boolean' ? <select {...attrs} className={INPUT} value={value === true ? 'yes' : value === false ? 'no' : ''} onChange={event => update(path, event.target.value === '' ? null : event.target.value === 'yes')}><option value="">Not specified</option><option value="yes">Yes</option><option value="no">No</option></select>
                : type === 'long' ? <textarea {...attrs} className={INPUT} rows={4} value={typeof value === 'string' ? value : ''} onChange={event => update(path, event.target.value)} />
                    : <input {...attrs} className={INPUT} value={typeof value === 'string' ? value : ''} onChange={event => update(path, event.target.value)} />}
        {error && <p id={`${path}-error`} className="mt-1 text-sm text-red-700">{error}</p>}
    </div>;
}

export default function BrandGuidelinesIndex({ brandGuideline, customer, canEdit }) {
    const page = usePage();
    const onboarding = new URLSearchParams((page.url || '').split('?')[1] || '').get('review') === '1';
    const [editing, setEditing] = useState(false);
    const [section, setSection] = useState('overview');
    const [extracting, setExtracting] = useState(!brandGuideline);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [selectedSuggestions, setSelectedSuggestions] = useState([]);
    const baseline = useRef(brandGuideline?.updated_at ?? null);
    const form = useForm(profileData(brandGuideline));
    useEffect(() => {
        if (!editing) form.setData(profileData(brandGuideline));
        setSelectedSuggestions([]);
    }, [brandGuideline?.id, brandGuideline?.profile_version, editing]);
    useEffect(() => {
        const first = Object.keys(form.errors)[0];
        if (!first || first === 'profile_version') return;
        const targetSection = first.startsWith('service_lines') ? 'services' : SECTIONS.find(item => item.fields.some(([path]) => first === path || first.startsWith(`${path}.`)))?.id;
        if (targetSection) setSection(targetSection);
    }, [form.errors]);
    const update = (path, value) => form.setData(previous => {
        const next = structuredClone(previous);
        const keys = path.split('.');
        let target = next;
        keys.slice(0, -1).forEach(key => { target[key] ??= {}; target = target[key]; });
        target[keys.at(-1)] = value;
        return next;
    });
    const action = (url, payload, onSuccess) => {
        setBusy(true);
        router.post(url, payload, { preserveScroll: true, onSuccess, onError: errors => { form.setError(errors); setExtracting(false); }, onFinish: () => setBusy(false) });
    };
    const extract = () => {
        setConfirm(false);
        baseline.current = brandGuideline?.updated_at ?? null;
        setExtracting(true);
        action(route('brand-guidelines.re-extract'), {}, undefined);
    };
    const save = event => {
        event.preventDefault();
        form.put(route('brand-guidelines.update', brandGuideline.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };
    const approve = () => action(route('brand-guidelines.verify', brandGuideline.id), { continue: onboarding, profile_version: brandGuideline.profile_version });
    const currentSection = SECTIONS.find(item => item.id === section);
    const draft = Boolean(brandGuideline?.proposed_profile);
    const pendingChanges = brandGuideline?.extraction_changes || [];
    const suggestions = brandGuideline?.source_suggestions || [];
    const savedValues = profileData(brandGuideline);
    const shown = editing ? form.data : savedValues;
    const fieldProps = { data: shown, update, editing, errors: form.errors };
    return <AuthenticatedLayout>
        <Head title="Business profile & brand guidelines" />
        <main className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
            {customer?.service_type === 'setup_only' && <SetupStages stage={0} />}
            <header><h1 className="text-2xl font-bold text-gray-900">{customer?.name} — Business profile</h1><p className="mt-2 text-sm text-gray-600">Your approved offer, audience, brand voice and boundaries guide new ads. Changes require your approval; existing ads stay as they are.</p></header>
            <BrandExtractionStatus watching={extracting} baselineUpdatedAt={baseline.current} onSettled={() => setExtracting(false)} />
            <FormErrorSummary errors={form.errors} labels={{ profile_version: 'Profile version' }} />
            {!brandGuideline ? <section className="rounded-xl border border-gray-200 bg-white p-6"><h2 className="text-xl font-semibold">Building your business profile</h2><p className="mt-2 text-gray-600">We use the included sources in your knowledge base. If the scan failed, add readable content and try again.</p><div className="mt-5 flex flex-wrap gap-3"><Link href={route('knowledge-base.index')} className={SECONDARY}>Review knowledge sources</Link><button onClick={extract} disabled={extracting || busy} className={ACTION}>Build profile</button></div></section> : <>
                {brandGuideline.extraction_warning && <section role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-5"><h2 className="font-semibold text-amber-900">Check that this is your business</h2><p className="mt-2 text-sm text-amber-900">{brandGuideline.extraction_warning}</p><p className="mt-2 text-sm text-amber-900">Check your sources and correct inaccurate claims before approval. Changing your website starts a new scan and excludes the previous website's pages.</p><Link href={route('customers.edit', customer.uuid)} className={`${ACTION} mt-4`}>Change website or business details</Link></section>}
                <section className="rounded-xl border border-gray-200 bg-white p-5">
                    <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="font-semibold text-gray-900">{brandGuideline.user_verified ? 'Approved business profile' : draft ? 'Draft ready for review' : 'Have we understood your business?'}</h2><p className="mt-1 text-sm text-gray-600">{draft ? 'This draft will replace your saved profile only when you approve it. Your corrections are kept when sources are analysed again.' : 'Check the offer, buyer and restrictions across the sections below before approving.'}</p><p className="mt-2 text-xs text-gray-500">Version {brandGuideline.profile_version} · {brandGuideline.user_verified ? 'Approved' : 'Approval required'} · Account: {customer.country} / {customer.currency_code}</p></div>
                    {!editing && canEdit && (!brandGuideline.user_verified || onboarding) && <button className={ACTION} disabled={busy || extracting} onClick={approve}>{brandGuideline.user_verified ? 'Continue to campaign' : onboarding ? 'Approve profile & continue' : 'Approve profile'}</button>}</div>
                    {pendingChanges.length > 0 && <details className="mt-5"><summary className="min-h-[44px] cursor-pointer font-medium text-brand-dark">Review {pendingChanges.length} proposed change{pendingChanges.length === 1 ? '' : 's'}</summary><ul className="mt-3 space-y-4">{pendingChanges.map(change => <li key={change.path} className="rounded-lg border border-gray-200 p-4"><h3 className="text-sm font-semibold capitalize">{change.path.replaceAll('.', ' / ').replaceAll('_', ' ')}</h3><div className="mt-2 grid gap-3 text-sm sm:grid-cols-2"><div><p className="text-xs text-gray-500">Saved</p><p className="whitespace-pre-wrap break-words">{readable(change.before)}</p></div><div><p className="text-xs text-gray-500">Proposed</p><p className="whitespace-pre-wrap break-words">{readable(change.after)}</p></div></div></li>)}</ul></details>}
                    {suggestions.length > 0 && <details className="mt-5"><summary className="min-h-[44px] cursor-pointer font-medium text-brand-dark">{suggestions.length} source suggestion{suggestions.length === 1 ? '' : 's'} differ from your confirmed corrections</summary><p className="mt-2 text-sm text-gray-600">Your correction wins. Select a suggestion only if you want to replace it in the draft.</p><div className="mt-3 space-y-3">{suggestions.map(change => <label key={change.path} className="flex cursor-pointer items-start gap-3 rounded-lg border p-4"><input type="checkbox" className="mt-1 rounded border-gray-300 text-brand-dark" checked={selectedSuggestions.includes(change.path)} onChange={event => setSelectedSuggestions(previous => event.target.checked ? [...previous, change.path] : previous.filter(path => path !== change.path))} /><span className="min-w-0 text-sm"><strong className="capitalize">{change.path.replaceAll('.', ' / ').replaceAll('_', ' ')}</strong><span className="mt-2 block whitespace-pre-wrap break-words">Your correction: {readable(change.before)}</span><span className="mt-2 block whitespace-pre-wrap break-words">Source suggestion: {readable(change.after)}</span></span></label>)}</div><button disabled={busy || editing || selectedSuggestions.length === 0} className={`${SECONDARY} mt-4`} onClick={() => action(route('brand-guidelines.suggestions', brandGuideline.id), { paths: selectedSuggestions, profile_version: brandGuideline.profile_version })}>Use selected suggestions in draft</button></details>}
                </section>
                <div className="flex flex-wrap gap-3">{canEdit && !editing && <><button className={SECONDARY} disabled={extracting || busy} onClick={() => setEditing(true)}>Edit profile</button><button className={SECONDARY} disabled={extracting || busy} onClick={() => setConfirm(true)}>Analyse included sources again</button></>}<a href={route('brand-guidelines.export-pdf')} className={SECONDARY}>Export saved profile</a><Link href={route('knowledge-base.index')} className={SECONDARY}>Manage knowledge sources</Link></div>
                <form onSubmit={save} className="grid gap-6 lg:grid-cols-[220px_1fr]">
                    <nav aria-label="Business profile sections" className="flex gap-2 overflow-x-auto pb-2 lg:flex-col lg:overflow-visible">{SECTIONS.map(item => <button key={item.id} type="button" aria-current={section === item.id ? 'page' : undefined} className={`min-h-[44px] shrink-0 rounded-lg px-3 py-2 text-left text-sm ${section === item.id ? 'bg-brand-tint-10 font-semibold text-brand-darker' : 'text-gray-700 hover:bg-gray-100'}`} onClick={() => setSection(item.id)}>{item.title}</button>)}</nav>
                    <section className="min-w-0 space-y-6 rounded-xl border border-gray-200 bg-white p-5 sm:p-6"><h2 className="text-xl font-semibold text-gray-900">{currentSection.title}</h2>
                        {currentSection.fields.map(([path, label, type]) => <ProfileField key={path} path={path} label={label} type={type} {...fieldProps} />)}
                        {section === 'services' && <div id="service_lines" tabIndex={-1} className="space-y-5">{(shown.service_lines || []).map((service, index) => <div key={index} className="space-y-4 rounded-lg border border-gray-200 p-4">{[['name', 'Product or service name'], ['description', 'Offer description', 'long'], ['target_audience', 'Who it is for', 'long'], ['messaging_themes', 'Messaging themes', 'list'], ['pain_points', 'Problems addressed', 'list']].map(([key, label, type]) => <ProfileField key={key} path={`service_lines.${index}.${key}`} label={label} type={type} {...fieldProps} />)}{editing && <button type="button" onClick={() => update('service_lines', shown.service_lines.filter((_, i) => i !== index))} className="min-h-[44px] text-sm font-medium text-red-700">Remove {service.name || `service ${index + 1}`}</button>}</div>)}{shown.service_lines?.length === 0 && <p className="text-sm text-gray-600">No offers recorded. Add the products or services you want the AI to understand.</p>}{editing && <button type="button" className={SECONDARY} onClick={() => update('service_lines', [...(shown.service_lines || []), { name: '', description: '', target_audience: '', messaging_themes: [], pain_points: [], content_volume: 'low' }])}>Add product or service</button>}</div>}
                        {editing && <div className="sticky bottom-0 flex flex-wrap gap-3 border-t bg-white py-4"><button type="button" className={SECONDARY} disabled={form.processing} onClick={() => { form.setData(profileData(brandGuideline)); form.clearErrors(); setEditing(false); }}>Cancel edits</button><button type="submit" className={ACTION} disabled={form.processing}>{form.processing ? 'Saving draft…' : 'Save draft'}</button><p className="w-full text-xs text-gray-500">Saving keeps a draft. Approval is a separate step.</p></div>}
                    </section>
                </form>
                <section className="rounded-xl border border-gray-200 bg-white p-5"><h2 className="font-semibold text-gray-900">Sources used for this analysis</h2><p className="mt-1 text-sm text-gray-600">These sources informed the profile. Individual claims can include AI interpretation; check them against your business facts.</p>{brandGuideline.source_snapshot?.length ? <ul className="mt-4 space-y-2 text-sm">{brandGuideline.source_snapshot.map((source, i) => <li key={source.id || `${source.url}-${i}`} className="break-words">{source.url && /^https?:\/\//i.test(source.url) ? <a href={source.url} target="_blank" rel="noreferrer" className="font-medium text-brand-dark underline">{source.label}</a> : source.label} <span className="text-gray-500">· {source.type === 'url' ? 'Website page' : 'Supplied content'}</span></li>)}</ul> : <p className="mt-3 text-sm text-gray-500">This older profile did not record its sources. Analyse included sources again to create a reviewable draft with provenance.</p>}</section>
            </>}
        </main>
        <ConfirmationModal show={confirm} onClose={() => setConfirm(false)} onConfirm={extract} title="Analyse included sources again" message="We will create a draft from the included knowledge sources and keep your corrections. Review the differences before approving. Your existing ads will not be changed." confirmText="Build new draft" isDestructive={false} confirmButtonClass="bg-brand-dark hover:bg-brand-darker" />
    </AuthenticatedLayout>;
}
