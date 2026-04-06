import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function Conversions({ conversions = [] }) {
    const statusColors = {
        pending: 'bg-yellow-100 text-yellow-700',
        uploaded_google: 'bg-blue-100 text-blue-700',
        uploaded_facebook: 'bg-indigo-100 text-indigo-700',
        uploaded_all: 'bg-green-100 text-green-700',
        failed: 'bg-red-100 text-red-700',
    };

    const hasFailed = conversions.some(c => c.upload_status === 'failed');

    return (
        <AuthenticatedLayout>
            <Head title="Offline Conversions" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Offline Conversions</h1>
                            <p className="mt-1 text-sm text-gray-500">CRM conversions synced to ad platforms for closed-loop attribution.</p>
                        </div>
                        <div className="flex gap-2">
                            {hasFailed && (
                                <button onClick={() => router.post(route('integrations.retry-upload'), {}, {preserveScroll: true})} className="px-4 py-2 text-sm text-red-600 border border-red-200 rounded-lg hover:bg-red-50">Retry Failed</button>
                            )}
                            <a href={route('integrations.index')} className="text-sm text-gray-500 hover:text-gray-700">← Integrations</a>
                        </div>
                    </div>

                    {conversions.length > 0 ? (
                        <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Conversion</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Click ID</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {conversions.map(c => (
                                        <tr key={c.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-3 text-sm text-gray-600">{new Date(c.conversion_time).toLocaleDateString()}</td>
                                            <td className="px-4 py-3 text-sm font-medium text-gray-900">{c.conversion_name}</td>
                                            <td className="px-4 py-3 text-right text-sm text-gray-900">{c.conversion_value ? `$${parseFloat(c.conversion_value).toLocaleString(undefined, {minimumFractionDigits: 2})}` : '—'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex gap-1">
                                                    {c.gclid && <span className="text-xs px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded">G</span>}
                                                    {c.fbclid && <span className="text-xs px-1.5 py-0.5 bg-indigo-50 text-indigo-600 rounded">FB</span>}
                                                    {c.msclid && <span className="text-xs px-1.5 py-0.5 bg-teal-50 text-teal-600 rounded">MS</span>}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`text-xs px-2 py-0.5 rounded ${statusColors[c.upload_status] || 'bg-gray-100 text-gray-500'}`}>{c.upload_status.replace('_', ' ')}</span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <h3 className="text-sm font-medium text-gray-900">No offline conversions yet</h3>
                            <p className="mt-1 text-sm text-gray-500">Connect a CRM to start syncing closed deals as offline conversions.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
