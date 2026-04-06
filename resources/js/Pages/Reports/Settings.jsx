import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Settings({ customer, canWhiteLabel }) {
    const branding = customer?.report_branding || {};
    const [form, setForm] = useState({
        enabled: branding.enabled || false,
        company_name: branding.company_name || '',
        logo_url: branding.logo_url || '',
        primary_color: branding.primary_color || '#f97316',
    });
    const [saving, setSaving] = useState(false);

    if (!canWhiteLabel) {
        return (
            <AuthenticatedLayout>
                <Head title="Report Branding" />
                <div className="py-8">
                    <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <svg className="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            <h3 className="mt-4 text-sm font-medium text-gray-900">Agency Plan Required</h3>
                            <p className="mt-1 text-sm text-gray-500">
                                White-label report branding is available on the Agency plan.
                            </p>
                            <div className="mt-6">
                                <a
                                    href={route('pricing')}
                                    className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors"
                                >
                                    View Plans
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    const handleSubmit = (e) => {
        e.preventDefault();
        setSaving(true);
        router.post(route('reports.branding.update'), form, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Report Branding" />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-8">
                        <h1 className="text-2xl font-bold text-gray-900">Report Branding</h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Customize reports with your agency's branding. These settings apply to all generated PDFs and emailed reports.
                        </p>
                    </div>

                    <form onSubmit={handleSubmit} className="bg-white rounded-lg border border-gray-200 divide-y divide-gray-200">
                        {/* Enable toggle */}
                        <div className="p-6">
                            <label className="flex items-center justify-between">
                                <div>
                                    <span className="text-sm font-medium text-gray-900">Enable White-Label Branding</span>
                                    <p className="text-sm text-gray-500">Replace Spectra branding with your own on all reports.</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setForm({ ...form, enabled: !form.enabled })}
                                    className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                                        form.enabled ? 'bg-flame-orange-600' : 'bg-gray-200'
                                    }`}
                                >
                                    <span
                                        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                            form.enabled ? 'translate-x-5' : 'translate-x-0'
                                        }`}
                                    />
                                </button>
                            </label>
                        </div>

                        {/* Company Name */}
                        <div className="p-6">
                            <label htmlFor="company_name" className="block text-sm font-medium text-gray-900 mb-1">
                                Company Name
                            </label>
                            <input
                                id="company_name"
                                type="text"
                                value={form.company_name}
                                onChange={(e) => setForm({ ...form, company_name: e.target.value })}
                                placeholder="Your Agency Name"
                                className="w-full rounded-lg border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500 text-sm"
                                disabled={!form.enabled}
                            />
                        </div>

                        {/* Logo URL */}
                        <div className="p-6">
                            <label htmlFor="logo_url" className="block text-sm font-medium text-gray-900 mb-1">
                                Logo URL
                            </label>
                            <input
                                id="logo_url"
                                type="url"
                                value={form.logo_url}
                                onChange={(e) => setForm({ ...form, logo_url: e.target.value })}
                                placeholder="https://your-agency.com/logo.png"
                                className="w-full rounded-lg border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500 text-sm"
                                disabled={!form.enabled}
                            />
                            <p className="mt-1 text-xs text-gray-400">Recommended: PNG or SVG, at least 200×60px.</p>
                            {form.logo_url && form.enabled && (
                                <div className="mt-3 p-3 bg-gray-50 rounded-lg">
                                    <p className="text-xs text-gray-500 mb-2">Preview:</p>
                                    <img src={form.logo_url} alt="Logo preview" className="h-10 object-contain" onError={(e) => e.target.style.display = 'none'} />
                                </div>
                            )}
                        </div>

                        {/* Primary Color */}
                        <div className="p-6">
                            <label htmlFor="primary_color" className="block text-sm font-medium text-gray-900 mb-1">
                                Primary Brand Color
                            </label>
                            <div className="flex items-center gap-3">
                                <input
                                    id="primary_color"
                                    type="color"
                                    value={form.primary_color}
                                    onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
                                    className="h-10 w-14 rounded border-gray-300 cursor-pointer"
                                    disabled={!form.enabled}
                                />
                                <input
                                    type="text"
                                    value={form.primary_color}
                                    onChange={(e) => {
                                        if (/^#[0-9a-fA-F]{0,6}$/.test(e.target.value)) {
                                            setForm({ ...form, primary_color: e.target.value });
                                        }
                                    }}
                                    className="w-28 rounded-lg border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500 text-sm font-mono"
                                    disabled={!form.enabled}
                                />
                                <div
                                    className="h-10 flex-1 rounded-lg"
                                    style={{ background: `linear-gradient(135deg, ${form.primary_color}, ${form.primary_color}88)` }}
                                />
                            </div>
                        </div>

                        {/* Submit */}
                        <div className="p-6 bg-gray-50 flex items-center justify-between rounded-b-lg">
                            <a href={route('reports.index')} className="text-sm text-gray-500 hover:text-gray-700">
                                ← Back to Reports
                            </a>
                            <button
                                type="submit"
                                disabled={saving}
                                className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors disabled:opacity-50"
                            >
                                {saving ? 'Saving...' : 'Save Branding'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
