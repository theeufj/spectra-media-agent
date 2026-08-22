import { lazy, Suspense, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import AdminShell from './AdminShell';
import EmailPreview from '@/Components/Admin/EmailPreview';

/**
 * Editing the follow-up chains.
 *
 * These emails go to people who are not customers yet, from a named founder's
 * address, so the page leads with whether anything is actually live and how
 * many people each chain would write to. Turning one on should never be a
 * surprise.
 *
 * One email per tab, with the rendered result beside the editor. The previous
 * accordion stacked every step in one column, which made the fourth email in a
 * chain something you scrolled past rather than read — and none of it showed
 * what the email actually looked like.
 */

// 133KB gzipped of editor that only matters once somebody switches a step to
// rich text. Split out so no other page in the admin portal carries it, and so
// a plain-text chain never loads it at all.
const EmailEditor = lazy(() => import('@/Components/Admin/EmailEditor'));

const Field = ({ label, hint, children }) => (
    <label className="block">
        <span className="text-sm font-medium text-gray-700">{label}</span>
        {hint && <span className="ml-2 text-xs text-gray-400">{hint}</span>}
        <div className="mt-1">{children}</div>
    </label>
);

const input = 'w-full rounded-lg border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500';
const primaryBtn = 'rounded-lg bg-flame-orange-500 px-4 py-2 text-sm font-medium text-white hover:bg-flame-orange-600 disabled:bg-gray-300';

const humanDelay = (hours) => {
    if (hours === 0) return 'immediately';
    if (hours < 24) return `${hours}h`;
    const days = hours / 24;
    return `${Number.isInteger(days) ? days : days.toFixed(1)}d`;
};

/* ── Test send ──────────────────────────────────────────────────────────── */

function TestSend({ step }) {
    const { auth } = usePage().props;
    const { data, setData, post, processing } = useForm({ email: auth?.user?.email ?? '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.email-sequence-steps.test', step.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
            <div className="min-w-[220px] flex-1">
                <Field label="Send a test to">
                    <input type="email" required value={data.email} onChange={(e) => setData('email', e.target.value)} className={input} />
                </Field>
            </div>
            <button type="submit" disabled={processing} className={primaryBtn}>
                {processing ? 'Sending…' : 'Send test'}
            </button>
            <p className="w-full text-xs text-gray-500">
                Sends this email now, with sample values for the placeholders. It is not recorded against anyone,
                so the real send to that person is unaffected — and it goes out whether or not the chain is live.
            </p>
        </form>
    );
}

/* ── One email ──────────────────────────────────────────────────────────── */

function StepEditor({ step, onDirtyChange, canDelete }) {
    const { data, setData, put, processing, isDirty } = useForm({
        subject: step.subject,
        body: step.body,
        format: step.format ?? 'plain',
        delay_hours: step.delay_hours,
        enabled: step.enabled,
    });

    // Reported upward so switching tabs can warn rather than silently
    // discarding a half-written email.
    onDirtyChange(isDirty);

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.email-sequence-steps.update', step.id), { preserveScroll: true });
    };

    const remove = () => {
        const warning = step.sent_count > 0
            ? `Delete email ${step.position}? It has been sent ${step.sent_count} times and that history is deleted with it.`
            : `Delete email ${step.position}?`;

        if (window.confirm(warning)) {
            router.delete(route('admin.email-sequence-steps.destroy', step.id), { preserveScroll: true });
        }
    };

    // Switching plain → rich keeps the words: the existing line breaks become
    // paragraphs rather than collapsing into one block, which is what happens
    // if raw text is handed straight to the editor.
    const switchFormat = (format) => {
        if (format === data.format) return;

        if (format === 'html') {
            const html = data.body
                .split(/\n{2,}/)
                .map((block) => `<p>${block.replace(/\n/g, '<br>').replace(/</g, '&lt;')}</p>`)
                .join('');
            setData({ ...data, format, body: html });
            return;
        }

        if (!window.confirm('Switch back to plain text? All formatting, images and links in this email are removed.')) {
            return;
        }

        const text = data.body
            .replace(/<\/(p|div|h[1-4]|li|blockquote)>/gi, '\n\n')
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/&nbsp;/g, ' ')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/\n{3,}/g, '\n\n')
            .trim();

        setData({ ...data, format, body: text });
    };

    return (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <form onSubmit={submit} className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <label className="flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="checkbox"
                            checked={data.enabled}
                            onChange={(e) => setData('enabled', e.target.checked)}
                            className="rounded border-gray-300 text-flame-orange-500 focus:ring-flame-orange-500"
                        />
                        This email is part of the chain
                    </label>

                    <div className="flex items-center gap-2 text-xs">
                        <span className="text-gray-500">{step.sent_count} sent</span>
                        <div className="flex rounded-md border border-gray-300 bg-white p-0.5">
                            {['plain', 'html'].map((f) => (
                                <button
                                    key={f}
                                    type="button"
                                    onClick={() => switchFormat(f)}
                                    className={`rounded px-2 py-1 transition ${
                                        data.format === f ? 'bg-flame-orange-500 text-white' : 'text-gray-600 hover:bg-gray-100'
                                    }`}
                                >
                                    {f === 'plain' ? 'Plain' : 'Rich'}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                <Field label="Sends after" hint="measured from when they joined the audience, not from the previous email">
                    <div className="flex items-center gap-2">
                        <input
                            type="number" min="0" max="2160"
                            value={data.delay_hours}
                            onChange={(e) => setData('delay_hours', Number(e.target.value))}
                            className={`${input} w-28`}
                        />
                        <span className="text-sm text-gray-500">hours — {humanDelay(data.delay_hours)}</span>
                    </div>
                </Field>

                <Field label="Subject">
                    <input type="text" value={data.subject} onChange={(e) => setData('subject', e.target.value)} className={input} />
                </Field>

                <Field
                    label="Body"
                    hint="{{ first_name }} and {{ website }} are replaced; an unknown one is removed"
                >
                    {data.format === 'html' ? (
                        <Suspense fallback={<div className="rounded-lg border border-gray-300 px-4 py-10 text-center text-sm text-gray-400">Loading editor…</div>}>
                            <EmailEditor
                                value={data.body}
                                onChange={(html) => setData('body', html)}
                                uploadUrl={route('admin.email-sequences.image')}
                                csrf={document.querySelector('meta[name="csrf-token"]')?.content ?? ''}
                            />
                        </Suspense>
                    ) : (
                        <textarea
                            rows={16}
                            value={data.body}
                            onChange={(e) => setData('body', e.target.value)}
                            className={`${input} font-mono text-xs`}
                        />
                    )}
                </Field>

                {data.format === 'html' && (
                    <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        Formatting is reduced on save to what email clients render — the toolbar only offers what
                        survives. These two chains read as a personal note from a founder, which is why they reply;
                        a heavily designed one reads as marketing.
                    </p>
                )}

                <div className="flex items-center justify-between gap-3">
                    {/* Disabled when there is nothing to save, so a primary
                        button reading "Saved" does not invite a pointless click. */}
                    <button type="submit" disabled={processing || !isDirty} className={primaryBtn}>
                        {processing ? 'Saving…' : isDirty ? 'Save changes' : 'Saved'}
                    </button>

                    {canDelete && (
                        <button type="button" onClick={remove} className="text-sm text-red-600 hover:underline">
                            Delete this email
                        </button>
                    )}
                </div>
            </form>

            <div className="space-y-4">
                <EmailPreview step={step} draft={{ subject: data.subject, body: data.body, format: data.format }} />
                <TestSend step={step} />
            </div>
        </div>
    );
}

/* ── One chain ──────────────────────────────────────────────────────────── */

function SequencePanel({ sequence }) {
    const [activeStep, setActiveStep] = useState(sequence.steps[0]?.id ?? null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const dirty = useRef(false);

    const { data, setData, put, processing } = useForm({
        label: sequence.label,
        from_email: sequence.from_email,
        from_name: sequence.from_name,
        reply_to: sequence.reply_to ?? '',
        signature: sequence.signature,
        enabled: sequence.enabled,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.email-sequences.update', sequence.id), { preserveScroll: true });
    };

    const switchStep = (id) => {
        if (id === activeStep) return;

        if (dirty.current && !window.confirm('This email has unsaved changes. Leave without saving?')) {
            return;
        }

        dirty.current = false;
        setActiveStep(id);
    };

    const addStep = () => {
        router.post(route('admin.email-sequence-steps.store', sequence.id), {}, {
            preserveScroll: true,
            // Land on the email that was just created, otherwise adding one
            // appears to do nothing but add a tab.
            onSuccess: (page) => {
                const updated = page.props.sequences.find((s) => s.id === sequence.id);
                const last = updated?.steps.at(-1);
                if (last) {
                    dirty.current = false;
                    setActiveStep(last.id);
                }
            },
        });
    };

    const step = sequence.steps.find((s) => s.id === activeStep) ?? sequence.steps[0];

    return (
        <div className="rounded-lg bg-white shadow">
            <div className="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 px-6 py-4">
                <div>
                    <h3 className="text-base font-semibold text-gray-900">{sequence.label}</h3>
                    <p className="mt-1 text-xs text-gray-500">{sequence.description}</p>
                    <p className="mt-1 text-xs text-gray-500">
                        <span className="font-medium">{sequence.audience_size}</span> people currently in this audience ·{' '}
                        {sequence.steps.filter((s) => s.enabled).length} of {sequence.steps.length} emails active
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <span
                        className={`whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ${
                            sequence.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
                        }`}
                    >
                        {sequence.enabled ? 'Live' : 'Off'}
                    </span>
                    <button onClick={() => setSettingsOpen(!settingsOpen)} className="text-sm font-medium text-flame-orange-600 hover:underline">
                        {settingsOpen ? 'Hide' : 'Sender'} settings
                    </button>
                </div>
            </div>

            {settingsOpen && (
                <form onSubmit={submit} className="grid grid-cols-1 gap-4 border-b border-gray-100 bg-gray-50 p-6 md:grid-cols-2">
                    <Field label="From name"><input type="text" value={data.from_name} onChange={(e) => setData('from_name', e.target.value)} className={input} /></Field>
                    <Field label="From address"><input type="email" value={data.from_email} onChange={(e) => setData('from_email', e.target.value)} className={input} /></Field>
                    <Field label="Reply-To" hint="blank sends replies to the From address">
                        <input type="email" value={data.reply_to} onChange={(e) => setData('reply_to', e.target.value)} className={input} />
                    </Field>
                    <Field label="Sign-off"><textarea rows={2} value={data.signature} onChange={(e) => setData('signature', e.target.value)} className={input} /></Field>

                    <div className="flex items-center justify-between gap-4 md:col-span-2">
                        <label className="flex items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={data.enabled}
                                onChange={(e) => setData('enabled', e.target.checked)}
                                className="rounded border-gray-300 text-flame-orange-500 focus:ring-flame-orange-500"
                            />
                            Send this chain to the {sequence.audience_size} people in its audience
                        </label>

                        <button type="submit" disabled={processing} className={primaryBtn}>
                            {processing ? 'Saving…' : 'Save'}
                        </button>
                    </div>
                </form>
            )}

            {/* One tab per email, in send order, each labelled with when it
                goes out — the chain's shape should be readable without
                opening anything. */}
            <div className="flex flex-wrap items-center gap-1 border-b border-gray-200 px-4 pt-3">
                {sequence.steps.map((s) => {
                    const active = s.id === step?.id;

                    return (
                        <button
                            key={s.id}
                            onClick={() => switchStep(s.id)}
                            className={`-mb-px flex items-center gap-2 rounded-t-lg border-b-2 px-4 py-2 text-sm transition ${
                                active
                                    ? 'border-flame-orange-500 font-medium text-flame-orange-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-800'
                            }`}
                        >
                            <span
                                className={`h-1.5 w-1.5 rounded-full ${s.enabled ? 'bg-green-500' : 'bg-gray-300'}`}
                                title={s.enabled ? 'Active' : 'Not sending'}
                            />
                            Email {s.position}
                            <span className="text-xs text-gray-400">{humanDelay(s.delay_hours)}</span>
                        </button>
                    );
                })}

                <button
                    onClick={addStep}
                    className="-mb-px rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm text-flame-orange-600 hover:text-flame-orange-700"
                    title="Add another email to this chain"
                >
                    + Add email
                </button>
            </div>

            <div className="p-6">
                {step ? (
                    <StepEditor
                        key={step.id}
                        step={step}
                        canDelete={sequence.steps.length > 1}
                        onDirtyChange={(value) => { dirty.current = value; }}
                    />
                ) : (
                    <p className="py-6 text-sm text-gray-500">
                        This chain has no emails yet. Add one to get started.
                    </p>
                )}
            </div>
        </div>
    );
}

/* ── Page ───────────────────────────────────────────────────────────────── */

export default function EmailSequences({ sequences, globallyEnabled, leads, replies }) {
    const [activeSequence, setActiveSequence] = useState(sequences[0]?.id ?? null);
    const sequence = sequences.find((s) => s.id === activeSequence) ?? sequences[0];

    return (
        <AdminShell
            title="Email Sequences"
            heading="Email sequences"
            subheading="Follow-up chains for people who tried the landing page or signed up and stopped."
        >
            {/* The master switch overrides every per-chain toggle, so a chain
                marked Live while this is off would otherwise be a lie. */}
            {!globallyEnabled && (
                <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <p className="text-sm text-amber-900">
                        <span className="font-semibold">Nothing is being sent.</span> Sequences are switched off
                        platform-wide via <code className="font-mono text-xs">EMAIL_SEQUENCES_ENABLED</code>. Chains marked
                        Live below will start only once that is turned on. Test sends below work regardless.
                    </p>
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {[
                    { label: 'Landing leads', value: leads.total, sub: 'gave us a website' },
                    { label: 'Still to hear from', value: leads.contactable, sub: 'not signed up, not unsubscribed' },
                    { label: 'Became users', value: leads.converted, sub: 'handed to the signup chain' },
                    { label: 'Unsubscribed', value: leads.unsubscribed, sub: 'never contacted again' },
                ].map((t) => (
                    <div key={t.label} className="rounded-lg bg-white p-6 shadow">
                        <p className="truncate text-sm font-medium text-gray-500">{t.label}</p>
                        <p className="mt-1 text-2xl font-bold tabular-nums text-gray-900">{t.value}</p>
                        <p className="mt-1 text-xs text-gray-400">{t.sub}</p>
                    </div>
                ))}
            </div>

            {sequences.length > 1 && (
                <div className="mb-4 flex flex-wrap gap-2">
                    {sequences.map((s) => (
                        <button
                            key={s.id}
                            onClick={() => setActiveSequence(s.id)}
                            className={`rounded-lg border px-4 py-2 text-sm transition ${
                                s.id === sequence?.id
                                    ? 'border-flame-orange-500 bg-flame-orange-50 font-medium text-flame-orange-700'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'
                            }`}
                        >
                            {s.label}
                            <span className="ml-2 text-xs text-gray-400">{s.steps.length} emails</span>
                        </button>
                    ))}
                </div>
            )}

            {sequence && <SequencePanel key={sequence.id} sequence={sequence} />}

            <div className="mt-6 rounded-lg bg-white shadow">
                <div className="border-b border-gray-200 px-6 py-4">
                    <h3 className="text-base font-semibold text-gray-900">Replies</h3>
                    <p className="mt-1 text-xs text-gray-500">
                        Captured from Resend and emailed to every admin as they arrive.
                    </p>
                </div>
                <div className="p-6">
                    {replies.length === 0 ? (
                        <p className="text-sm text-gray-500">No replies yet.</p>
                    ) : (
                        <div className="space-y-3">
                            {replies.map((r) => (
                                <div key={r.id} className="rounded-lg border border-gray-200 p-4">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="text-sm font-medium text-gray-900">{r.from_email}</span>
                                        <span className="text-xs text-gray-400">{r.received_at}</span>
                                    </div>
                                    {r.subject && <p className="mt-1 text-sm text-gray-700">{r.subject}</p>}
                                    <p className="mt-2 whitespace-pre-wrap text-sm text-gray-600">{r.body}</p>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AdminShell>
    );
}
