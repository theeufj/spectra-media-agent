import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminShell from './AdminShell';

/**
 * Editing the follow-up chains.
 *
 * These emails go to people who are not customers yet, from a named founder's
 * address, so the page leads with whether anything is actually live and how
 * many people each chain would write to. Turning one on should never be a
 * surprise.
 */

const Field = ({ label, hint, children }) => (
    <label className="block">
        <span className="text-sm font-medium text-gray-700">{label}</span>
        {hint && <span className="ml-2 text-xs text-gray-400">{hint}</span>}
        <div className="mt-1">{children}</div>
    </label>
);

const input = 'w-full rounded-lg border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500';

function StepEditor({ step }) {
    const { data, setData, put, processing } = useForm({
        subject: step.subject,
        body: step.body,
        delay_hours: step.delay_hours,
        enabled: step.enabled,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.email-sequence-steps.update', step.id), { preserveScroll: true });
    };

    const days = (data.delay_hours / 24).toFixed(1).replace('.0', '');

    return (
        <form onSubmit={submit} className="rounded-lg border border-gray-200 p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h4 className="text-sm font-semibold text-gray-900">Email {step.position}</h4>
                <div className="flex items-center gap-3 text-xs text-gray-500">
                    <span>{step.sent_count} sent</span>
                    <label className="flex items-center gap-1.5">
                        <input
                            type="checkbox"
                            checked={data.enabled}
                            onChange={(e) => setData('enabled', e.target.checked)}
                            className="rounded border-gray-300 text-flame-orange-500 focus:ring-flame-orange-500"
                        />
                        Enabled
                    </label>
                </div>
            </div>

            <div className="space-y-3">
                <Field label="Sends after" hint={`${days} day${days === '1' ? '' : 's'} from when they joined the audience`}>
                    <div className="flex items-center gap-2">
                        <input
                            type="number" min="0" max="2160"
                            value={data.delay_hours}
                            onChange={(e) => setData('delay_hours', Number(e.target.value))}
                            className={`${input} w-28`}
                        />
                        <span className="text-sm text-gray-500">hours</span>
                    </div>
                </Field>

                <Field label="Subject">
                    <input type="text" value={data.subject} onChange={(e) => setData('subject', e.target.value)} className={input} />
                </Field>

                <Field label="Body" hint="{{ first_name }} and {{ website }} are replaced; an unknown one is removed">
                    <textarea rows={9} value={data.body} onChange={(e) => setData('body', e.target.value)} className={`${input} font-mono text-xs`} />
                </Field>
            </div>

            <button
                type="submit"
                disabled={processing}
                className="mt-3 rounded-lg bg-flame-orange-500 px-4 py-2 text-sm font-medium text-white hover:bg-flame-orange-600 disabled:bg-gray-300"
            >
                {processing ? 'Saving…' : 'Save email'}
            </button>
        </form>
    );
}

function SequenceCard({ sequence }) {
    const [open, setOpen] = useState(false);
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

    return (
        <div className="rounded-lg bg-white shadow">
            <div className="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-4">
                <div>
                    <h3 className="text-base font-semibold text-gray-900">{sequence.label}</h3>
                    <p className="mt-1 text-xs text-gray-500">{sequence.description}</p>
                    <p className="mt-1 text-xs text-gray-500">
                        <span className="font-medium">{sequence.audience_size}</span> people currently in this audience ·{' '}
                        {sequence.steps.length} emails
                    </p>
                </div>
                <span
                    className={`whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ${
                        sequence.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
                    }`}
                >
                    {sequence.enabled ? 'Live' : 'Off'}
                </span>
            </div>

            <form onSubmit={submit} className="grid grid-cols-1 gap-4 border-b border-gray-100 p-6 md:grid-cols-2">
                <Field label="From name"><input type="text" value={data.from_name} onChange={(e) => setData('from_name', e.target.value)} className={input} /></Field>
                <Field label="From address"><input type="email" value={data.from_email} onChange={(e) => setData('from_email', e.target.value)} className={input} /></Field>
                <Field label="Reply-To" hint="blank sends replies to the From address">
                    <input type="email" value={data.reply_to} onChange={(e) => setData('reply_to', e.target.value)} className={input} />
                </Field>
                <Field label="Sign-off"><textarea rows={2} value={data.signature} onChange={(e) => setData('signature', e.target.value)} className={input} /></Field>

                <div className="md:col-span-2 flex items-center justify-between gap-4">
                    <label className="flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="checkbox"
                            checked={data.enabled}
                            onChange={(e) => setData('enabled', e.target.checked)}
                            className="rounded border-gray-300 text-flame-orange-500 focus:ring-flame-orange-500"
                        />
                        Send this chain to the {sequence.audience_size} people in its audience
                    </label>

                    <button type="submit" disabled={processing} className="rounded-lg bg-flame-orange-500 px-4 py-2 text-sm font-medium text-white hover:bg-flame-orange-600 disabled:bg-gray-300">
                        {processing ? 'Saving…' : 'Save'}
                    </button>
                </div>
            </form>

            <div className="p-6">
                <button onClick={() => setOpen(!open)} className="text-sm font-medium text-flame-orange-600 hover:underline">
                    {open ? 'Hide' : 'Edit'} the {sequence.steps.length} emails
                </button>

                {open && (
                    <div className="mt-4 space-y-4">
                        {sequence.steps.map((step) => <StepEditor key={step.id} step={step} />)}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function EmailSequences({ sequences, globallyEnabled, leads, replies }) {
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
                        Live below will start only once that is turned on. Use{' '}
                        <code className="font-mono text-xs">php artisan sequences:preview</code> to send yourself the copy first.
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

            <div className="space-y-6">
                {sequences.map((s) => <SequenceCard key={s.id} sequence={s} />)}
            </div>

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
