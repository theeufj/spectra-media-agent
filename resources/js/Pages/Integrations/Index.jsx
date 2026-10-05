import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { money, count } from '@/utils/format';
import { useCurrency } from '@/hooks/useCurrency';
import { Head, router, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FormErrorSummary from '@/Components/FormErrorSummary';
import ConfirmationModal from '@/Components/ConfirmationModal';
import { usePolling } from '@/hooks/usePolling';

function StatCard({ label, value, sub }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <p className="text-xs text-gray-500">{label}</p>
            <p className="text-xl font-bold text-gray-900 mt-1">{value}</p>
            {sub && <p className="text-xs text-gray-500 mt-0.5">{sub}</p>}
        </div>
    );
}

function IntegrationCard({ integration, onRepair, onDisconnect }) {
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
            <div className="flex flex-wrap gap-2">
                {integration.status !== 'disconnected' && (
                    <>
                        <button disabled={integration.status === 'syncing' || integration.status === 'error'} onClick={() => router.post(route('integrations.sync', integration.id), {}, {preserveScroll: true})} className="text-xs px-3 py-1.5 bg-brand-tint-10 text-brand-darker rounded-lg hover:bg-brand-tint-20 font-medium">{integration.status === 'syncing' ? 'Syncing…' : 'Sync now'}</button>
                        <button onClick={() => onRepair(integration.provider)} className="text-xs px-3 py-1.5 text-brand-dark underline">{integration.status === 'error' ? 'Repair connection' : 'Replace token'}</button>
                        <button onClick={() => onDisconnect(integration)} className="text-xs px-3 py-1.5 text-red-600 hover:bg-red-50 rounded-lg">Disconnect</button>
                    </>
                )}
            </div>
        </div>
    );
}

function ConnectForm({ provider, onClose }) {
    const { data: form, setData: setForm, post, processing: saving, errors } = useForm({ provider: provider.id, access_token: '', instance_url: '' });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('integrations.connect'), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form id="crm-connection" onSubmit={handleSubmit} className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <h3 className="text-sm font-semibold text-gray-900 mb-4">Connect {provider.name}</h3><p className="mb-4 text-sm text-gray-600">Use a CRM API token with access to closed deals and contacts. Spectra sends eligible sales back to your ad platforms. Your existing history is kept when replacing a token.</p><FormErrorSummary errors={errors} className="mb-4" />
            <div className="space-y-3">
                <div>
                    <label htmlFor="access_token" className="block text-xs font-medium text-gray-700 mb-1">API Access Token</label>
                    <input id="access_token" autoFocus aria-invalid={Boolean(errors.access_token)} autoComplete="off" type="password" value={form.access_token} onChange={e => setForm({...form, access_token: e.target.value})} required placeholder="Enter your API token" className="w-full rounded-lg border-gray-300 text-sm" />
                </div>
                {provider.id === 'salesforce' && (
                    <div>
                        <label htmlFor="instance_url" className="block text-xs font-medium text-gray-700 mb-1">Instance URL</label>
                        <input id="instance_url" aria-invalid={Boolean(errors.instance_url)} type="url" value={form.instance_url} onChange={e => setForm({...form, instance_url: e.target.value})} required placeholder="https://yourorg.salesforce.com" className="w-full rounded-lg border-gray-300 text-sm" />
                    </div>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-600">Cancel</button>
                    <button type="submit" disabled={saving} className="px-4 py-2 text-sm font-medium text-white bg-brand-dark rounded-lg hover:bg-brand-darker disabled:opacity-50">
                        {saving ? 'Connecting...' : 'Connect'}
                    </button>
                </div>
            </div>
        </form>
    );
}

export default function Index({ integrations = [], conversionStats, availableProviders = [] }) {
    const currency = useCurrency();
    const [connectingProvider, setConnectingProvider] = useState(null);
    const [records, setRecords] = useState(integrations);
    const [disconnecting, setDisconnecting] = useState(null);
    const [timedOut, setTimedOut] = useState(false);
    const [watchAttempt, setWatchAttempt] = useState(0);
    useEffect(() => setRecords(integrations), [integrations]);
    const syncing = records.some(record => record.status === 'syncing');
    useEffect(() => {
        if (!syncing) { setTimedOut(false); return; }
        const timer = setTimeout(() => setTimedOut(true), 10 * 60 * 1000);
        return () => clearTimeout(timer);
    }, [syncing, watchAttempt]);
    const { data: progress, failureStreak } = usePolling(route('integrations.status'), { enabled: syncing && !timedOut, interval: 5000, restartKey: watchAttempt, parse: result => { if (!Array.isArray(result?.integrations)) throw new Error('Invalid CRM status response'); return result; }, until: result => !result.integrations.some(record => record.status === 'syncing') });
    useEffect(() => { if (progress) { setRecords(progress.integrations); if (!progress.integrations.some(record => record.status === 'syncing')) router.reload({ only: ['conversionStats'], preserveScroll: true }); } }, [progress]);
    const repair = (providerId) => { setConnectingProvider(availableProviders.find(provider => provider.id === providerId)); requestAnimationFrame(() => document.getElementById('access_token')?.focus()); };
    const connected = records.filter(i => i.status !== 'disconnected');
    const connectedIds = connected.map(i => i.provider);
    const unconnected = availableProviders.filter(p => !connectedIds.includes(p.id));

    /*
     * Before a CRM is connected there is nothing to count, and the page opened
     * with four cards reading 0, 0, 0 and $0.00 plus a "View Conversions" link
     * to an empty table. Four zeros read as a broken integration rather than an
     * absent one. The stats appear with the first connection; until then the
     * page is just the thing you came to do.
     */
    const hasAnything = connected.length > 0 || (conversionStats?.total ?? 0) > 0;

    return (
        <AuthenticatedLayout>
            <Head title="Integrations" /><ConfirmationModal show={Boolean(disconnecting)} onClose={() => setDisconnecting(null)} title="Disconnect CRM?" message="New sales will stop syncing. Previously imported conversions stay in your history." confirmText="Disconnect" isDestructive onConfirm={() => new Promise((resolve, reject) => router.post(route('integrations.disconnect', disconnecting.id), {}, { preserveScroll: true, onSuccess: resolve, onError: () => reject(new Error('We could not disconnect. Try again.')) }))} />
            <div className="py-8">
                <div className="mx-auto max-w-5xl">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">CRM Integrations</h1>
                            <p className="mt-1 text-sm text-gray-500">Connect your CRM to sync offline conversions back to ad platforms.</p>
                        </div>
                        {hasAnything && (
                            <Link href={route('integrations.conversions')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">View Conversions</Link>
                        )}
                    </div>

                    {syncing && <p role="status" className="mb-4 rounded-lg bg-blue-50 p-4 text-sm text-blue-800">Your CRM sync is queued or running. The last successful sync and lead counts update automatically here.</p>}
                    {(timedOut || failureStreak >= 3) && <p role="alert" className="mb-4 text-sm text-amber-800">We cannot confirm the latest progress. <button type="button" onClick={() => { setTimedOut(false); setWatchAttempt(attempt => attempt + 1); router.reload(); }} className="font-semibold underline">Check status again</button></p>}
                    {/* Stats — only once there is something to count. */}
                    {hasAnything && (
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                            <StatCard label="Total Conversions" value={count(conversionStats?.total || 0)} />
                            <StatCard label="Pending Upload" value={count(conversionStats?.pending || 0)} />
                            <StatCard label="Uploaded" value={count(conversionStats?.uploaded || 0)} />
                            <StatCard label="Total Value" value={money(conversionStats?.total_value || 0, currency)} />
                        </div>
                    )}

                    {!hasAnything && (
                        <p className="mb-6 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600">
                            When a lead from your ads becomes a real sale in your CRM, connecting it here
                            sends that back to Google and Facebook — so they optimise towards the clicks
                            that actually make you money, not just the ones that fill in a form.
                        </p>
                    )}

                    {/* Connect Form */}
                    {connectingProvider && <ConnectForm key={connectingProvider.id} provider={connectingProvider} onClose={() => setConnectingProvider(null)} />}

                    {/* Connected Integrations */}
                    {connected.length > 0 && (
                        <div className="mb-6">
                            <h2 className="text-sm font-semibold text-gray-700 mb-3">Connections</h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {connected.map(i => <IntegrationCard key={i.id} integration={i} onRepair={repair} onDisconnect={setDisconnecting} />)}
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
                                        <button onClick={() => setConnectingProvider(p)} className="text-xs px-3 py-1.5 bg-brand-tint-10 text-brand-darker rounded-lg hover:bg-brand-tint-20 font-medium">Connect</button>
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
