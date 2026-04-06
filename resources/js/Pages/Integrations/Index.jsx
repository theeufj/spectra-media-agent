import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

function StatCard({ label, value, sub }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <p className="text-xs text-gray-500">{label}</p>
            <p className="text-xl font-bold text-gray-900 mt-1">{value}</p>
            {sub && <p className="text-xs text-gray-400 mt-0.5">{sub}</p>}
        </div>
    );
}

function IntegrationCard({ integration }) {
    const statusColors = {
        connected: 'bg-green-100 text-green-700',
        syncing: 'bg-blue-100 text-blue-700',
        error: 'bg-red-100 text-red-700',
        disconnected: 'bg-gray-100 text-gray-500',
    };

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-gray-900 capitalize">{integration.provider}</h3>
                <span className={`text-xs px-2 py-0.5 rounded ${statusColors[integration.status] || 'bg-gray-100 text-gray-500'}`}>{integration.status}</span>
            </div>
            <div className="text-xs text-gray-500 mb-3 space-y-1">
                <p>{integration.total_leads_synced} leads synced · {integration.total_conversions_uploaded} uploaded</p>
                {integration.last_synced_at && <p>Last sync: {new Date(integration.last_synced_at).toLocaleDateString()}</p>}
                {integration.last_error && <p className="text-red-500">{integration.last_error}</p>}
            </div>
            <div className="flex gap-2">
                {integration.status !== 'disconnected' && (
                    <>
                        <button onClick={() => router.post(route('integrations.sync', integration.id), {}, {preserveScroll: true})} className="text-xs px-3 py-1.5 bg-flame-orange-50 text-flame-orange-700 rounded-lg hover:bg-flame-orange-100 font-medium">Sync Now</button>
                        <button onClick={() => { if (confirm('Disconnect this integration?')) router.post(route('integrations.disconnect', integration.id), {}, {preserveScroll: true}); }} className="text-xs px-3 py-1.5 text-red-600 hover:bg-red-50 rounded-lg">Disconnect</button>
                    </>
                )}
            </div>
        </div>
    );
}

function ConnectForm({ provider, onClose }) {
    const [form, setForm] = useState({ provider: provider.id, access_token: '', instance_url: '' });
    const [saving, setSaving] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        setSaving(true);
        router.post(route('integrations.connect'), form, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <form onSubmit={handleSubmit} className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <h3 className="text-sm font-semibold text-gray-900 mb-4">Connect {provider.name}</h3>
            <div className="space-y-3">
                <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">API Access Token</label>
                    <input type="password" value={form.access_token} onChange={e => setForm({...form, access_token: e.target.value})} required placeholder="Enter your API token" className="w-full rounded-lg border-gray-300 text-sm" />
                </div>
                {provider.id === 'salesforce' && (
                    <div>
                        <label className="block text-xs font-medium text-gray-700 mb-1">Instance URL</label>
                        <input type="url" value={form.instance_url} onChange={e => setForm({...form, instance_url: e.target.value})} required placeholder="https://yourorg.salesforce.com" className="w-full rounded-lg border-gray-300 text-sm" />
                    </div>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-600">Cancel</button>
                    <button type="submit" disabled={saving} className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 disabled:opacity-50">
                        {saving ? 'Connecting...' : 'Connect'}
                    </button>
                </div>
            </div>
        </form>
    );
}

export default function Index({ integrations = [], conversionStats, availableProviders = [] }) {
    const [connectingProvider, setConnectingProvider] = useState(null);
    const connectedIds = integrations.filter(i => i.status !== 'disconnected').map(i => i.provider);
    const unconnected = availableProviders.filter(p => !connectedIds.includes(p.id));

    return (
        <AuthenticatedLayout>
            <Head title="Integrations" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">CRM Integrations</h1>
                            <p className="mt-1 text-sm text-gray-500">Connect your CRM to sync offline conversions back to ad platforms.</p>
                        </div>
                        <a href={route('integrations.conversions')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">View Conversions</a>
                    </div>

                    {/* Stats */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <StatCard label="Total Conversions" value={conversionStats?.total || 0} />
                        <StatCard label="Pending Upload" value={conversionStats?.pending || 0} />
                        <StatCard label="Uploaded" value={conversionStats?.uploaded || 0} />
                        <StatCard label="Total Value" value={`$${parseFloat(conversionStats?.total_value || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}`} />
                    </div>

                    {/* Connect Form */}
                    {connectingProvider && <ConnectForm provider={connectingProvider} onClose={() => setConnectingProvider(null)} />}

                    {/* Connected Integrations */}
                    {integrations.filter(i => i.status !== 'disconnected').length > 0 && (
                        <div className="mb-6">
                            <h2 className="text-sm font-semibold text-gray-700 mb-3">Connected</h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {integrations.filter(i => i.status !== 'disconnected').map(i => <IntegrationCard key={i.id} integration={i} />)}
                            </div>
                        </div>
                    )}

                    {/* Available to Connect */}
                    {unconnected.length > 0 && (
                        <div>
                            <h2 className="text-sm font-semibold text-gray-700 mb-3">Available</h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {unconnected.map(p => (
                                    <div key={p.id} className="bg-white rounded-lg border border-gray-200 border-dashed p-5">
                                        <h3 className="text-sm font-semibold text-gray-900">{p.name}</h3>
                                        <p className="text-xs text-gray-500 mt-1 mb-3">{p.description}</p>
                                        <button onClick={() => setConnectingProvider(p)} className="text-xs px-3 py-1.5 bg-flame-orange-50 text-flame-orange-700 rounded-lg hover:bg-flame-orange-100 font-medium">Connect</button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
