import React, { useMemo, useState } from 'react';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SideNav from './SideNav';

const RECIPIENT_LABELS = {
    admins: 'Admins only',
    customers: 'Customers only',
    both: 'Admins + customers',
};

// Client-side {{placeholder}} substitution mirroring NotificationTemplateService::render.
function render(tpl, vars) {
    if (!tpl) return '';
    return tpl.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (m, k) =>
        Object.prototype.hasOwnProperty.call(vars || {}, k) ? String(vars[k]) : m
    );
}

export default function NotificationTemplates({ templates, recipientOptions }) {
    const { flash } = usePage().props;
    const [selectedKey, setSelectedKey] = useState(templates[0]?.key ?? null);
    const selected = templates.find((t) => t.key === selectedKey) ?? null;

    const grouped = useMemo(() => {
        const g = {};
        templates.forEach((t) => {
            (g[t.category] = g[t.category] || []).push(t);
        });
        return g;
    }, [templates]);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Admin - Email Templates</h2>}
        >
            <Head title="Admin - Email Templates" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-6xl mx-auto">
                        <div className="mb-6">
                            <h3 className="text-2xl font-bold text-gray-900">Notification Email Templates</h3>
                            <p className="text-sm text-gray-500 mt-1">
                                Control who receives each automated email, edit its copy, or switch it off.
                                Leave copy blank to use the built-in default. Changes take effect immediately.
                            </p>
                        </div>

                        {flash?.message && (
                            <div className={`mb-4 rounded-md border px-4 py-3 text-sm ${
                                flash.type === 'error'
                                    ? 'bg-red-50 border-red-200 text-red-800'
                                    : 'bg-green-50 border-green-200 text-green-800'
                            }`}>
                                {flash.message}
                            </div>
                        )}

                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            {/* Template list */}
                            <div className="lg:col-span-1 bg-white shadow rounded-lg overflow-hidden max-h-[75vh] overflow-y-auto">
                                {Object.entries(grouped).map(([category, items]) => (
                                    <div key={category}>
                                        <div className="px-4 py-2 text-xs font-semibold text-gray-400 uppercase tracking-wider bg-gray-50 sticky top-0">
                                            {category}
                                        </div>
                                        {items.map((t) => (
                                            <button
                                                key={t.key}
                                                onClick={() => setSelectedKey(t.key)}
                                                className={`w-full text-left px-4 py-3 border-b border-gray-100 hover:bg-gray-50 ${
                                                    t.key === selectedKey ? 'bg-flame-orange-50 border-l-4 border-l-flame-orange-500' : ''
                                                }`}
                                            >
                                                <div className="flex items-center justify-between">
                                                    <span className="text-sm font-medium text-gray-800">{t.label}</span>
                                                    <span className="flex items-center gap-1">
                                                        {t.customized && (
                                                            <span className="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">edited</span>
                                                        )}
                                                        {!t.enabled && (
                                                            <span className="text-[10px] px-1.5 py-0.5 rounded bg-red-100 text-red-700">off</span>
                                                        )}
                                                    </span>
                                                </div>
                                                <div className="text-xs text-gray-400 mt-0.5">{RECIPIENT_LABELS[t.recipients]}</div>
                                            </button>
                                        ))}
                                    </div>
                                ))}
                            </div>

                            {/* Editor */}
                            <div className="lg:col-span-2">
                                {selected ? (
                                    <TemplateEditor key={selected.key} template={selected} recipientOptions={recipientOptions} />
                                ) : (
                                    <div className="bg-white shadow rounded-lg p-8 text-gray-500">Select a template to edit.</div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function TemplateEditor({ template, recipientOptions }) {
    const { data, setData, post, processing } = useForm({
        key: template.key,
        subject: template.subject ?? '',
        body: template.body ?? '',
        recipients: template.recipients,
        enabled: template.enabled,
    });

    const vars = template.variables || {};
    const defaultSubject = `✨ ${vars.title ?? template.label}`;
    const defaultBody = vars.message ?? '';

    const previewSubject = render(data.subject || defaultSubject, vars);
    const previewBody = render(data.body || defaultBody, vars);

    const save = (e) => {
        e.preventDefault();
        post(route('admin.notification-templates.update'), { preserveScroll: true });
    };

    const sendTest = () => {
        router.post(
            route('admin.notification-templates.test'),
            { key: data.key, subject: data.subject, body: data.body },
            { preserveScroll: true }
        );
    };

    return (
        <div className="bg-white shadow rounded-lg overflow-hidden">
            <div className="px-6 py-4 bg-gradient-to-r from-purple-600 to-flame-orange-600">
                <h3 className="text-lg font-semibold text-white">{template.label}</h3>
                {template.description && <p className="text-sm text-purple-100 mt-1">{template.description}</p>}
            </div>

            <form onSubmit={save} className="p-6 space-y-5">
                {/* Recipients + enabled */}
                <div className="flex flex-wrap items-center gap-6">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Recipients</label>
                        <select
                            value={data.recipients}
                            onChange={(e) => setData('recipients', e.target.value)}
                            className="rounded-md border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                        >
                            {recipientOptions.map((r) => (
                                <option key={r} value={r}>{RECIPIENT_LABELS[r]}</option>
                            ))}
                        </select>
                    </div>
                    <label className="flex items-center gap-2 mt-6 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={data.enabled}
                            onChange={(e) => setData('enabled', e.target.checked)}
                            className="rounded border-gray-300 text-flame-orange-600 focus:ring-flame-orange-500"
                        />
                        <span className="text-sm text-gray-700">Enabled {data.enabled ? '' : '(this email is switched off)'}</span>
                    </label>
                </div>

                {/* Variables */}
                {Object.keys(vars).length > 0 && (
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Available variables</label>
                        <div className="flex flex-wrap gap-2">
                            {Object.keys(vars).map((v) => (
                                <code key={v} className="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700 border border-gray-200">
                                    {`{{${v}}}`}
                                </code>
                            ))}
                        </div>
                    </div>
                )}

                {/* Subject */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                    <input
                        type="text"
                        value={data.subject}
                        onChange={(e) => setData('subject', e.target.value)}
                        placeholder={`Default: ${defaultSubject}`}
                        className="w-full rounded-md border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                    />
                </div>

                {/* Body */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Body</label>
                    <textarea
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        rows={5}
                        placeholder={defaultBody ? `Default: ${defaultBody}` : 'Uses the built-in default copy when left blank.'}
                        className="w-full rounded-md border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                    />
                    <p className="text-xs text-gray-400 mt-1">Leave blank to keep the built-in default copy.</p>
                </div>

                {/* Preview */}
                <div className="border border-gray-200 rounded-lg overflow-hidden">
                    <div className="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                        Preview (sample data)
                    </div>
                    <div className="p-4">
                        <div className="text-sm font-semibold text-gray-900">{previewSubject}</div>
                        <div className="text-sm text-gray-700 mt-2 whitespace-pre-line">{previewBody}</div>
                    </div>
                </div>

                <div className="flex items-center gap-3 pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="px-4 py-2 bg-flame-orange-600 text-white text-sm font-medium rounded-md hover:bg-flame-orange-700 disabled:opacity-50"
                    >
                        {processing ? 'Saving…' : 'Save'}
                    </button>
                    <button
                        type="button"
                        onClick={sendTest}
                        className="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50"
                    >
                        Send test to me
                    </button>
                </div>
            </form>
        </div>
    );
}
