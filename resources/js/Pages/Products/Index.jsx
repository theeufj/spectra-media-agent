import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

function StatCard({ label, value, color }) {
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-4">
            <p className="text-xs text-gray-500">{label}</p>
            <p className={`text-xl font-bold mt-1 ${color || 'text-gray-900'}`}>{value}</p>
        </div>
    );
}

function FeedCard({ feed }) {
    const statusColors = {
        active: 'bg-green-100 text-green-700',
        processing: 'bg-blue-100 text-blue-700',
        error: 'bg-red-100 text-red-700',
        pending: 'bg-yellow-100 text-yellow-700',
    };

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-3">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900">{feed.feed_name}</h3>
                    <p className="text-xs text-gray-500">Merchant ID: {feed.merchant_id} · {feed.source_type}</p>
                </div>
                <span className={`text-xs px-2 py-0.5 rounded ${statusColors[feed.status] || 'bg-gray-100 text-gray-500'}`}>{feed.status}</span>
            </div>
            <div className="grid grid-cols-3 gap-3 mb-3 text-center">
                <div><p className="text-lg font-bold text-gray-900">{feed.total_products}</p><p className="text-xs text-gray-500">Total</p></div>
                <div><p className="text-lg font-bold text-green-600">{feed.approved_products}</p><p className="text-xs text-gray-500">Approved</p></div>
                <div><p className="text-lg font-bold text-red-600">{feed.disapproved_products}</p><p className="text-xs text-gray-500">Issues</p></div>
            </div>
            {/* Health bar */}
            {feed.total_products > 0 && (
                <div className="w-full bg-gray-200 rounded-full h-2 mb-3">
                    <div className="bg-green-500 h-2 rounded-full" style={{width: `${(feed.approved_products / feed.total_products * 100).toFixed(0)}%`}} />
                </div>
            )}
            <div className="flex items-center justify-between">
                <span className="text-xs text-gray-400">{feed.last_synced_at ? `Synced: ${new Date(feed.last_synced_at).toLocaleDateString()}` : 'Never synced'}</span>
                <div className="flex gap-2">
                    <button onClick={() => router.post(route('products.feeds.sync', feed.id), {}, {preserveScroll: true})} className="text-xs px-3 py-1.5 bg-flame-orange-50 text-flame-orange-700 rounded-lg hover:bg-flame-orange-100 font-medium">Sync</button>
                    <button onClick={() => { if (confirm('Delete this feed?')) router.delete(route('products.feeds.delete', feed.id), {preserveScroll: true}); }} className="text-xs px-3 py-1.5 text-red-500 hover:bg-red-50 rounded-lg">Delete</button>
                </div>
            </div>
            {feed.last_error && <p className="text-xs text-red-500 mt-2">{feed.last_error}</p>}
        </div>
    );
}

export default function Index({ feeds = [], stats }) {
    const [showCreate, setShowCreate] = useState(false);
    const [form, setForm] = useState({ feed_name: '', merchant_id: '', source_type: 'api', source_url: '', sync_frequency: 'daily' });
    const [saving, setSaving] = useState(false);

    const handleCreate = (e) => {
        e.preventDefault();
        setSaving(true);
        router.post(route('products.feeds.create'), form, {
            preserveScroll: true,
            onSuccess: () => { setShowCreate(false); setForm({ feed_name: '', merchant_id: '', source_type: 'api', source_url: '', sync_frequency: 'daily' }); },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Product Feeds" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Google Shopping & Product Feeds</h1>
                            <p className="mt-1 text-sm text-gray-500">Manage Merchant Center feeds and monitor product health.</p>
                        </div>
                        <div className="flex gap-2">
                            <a href={route('products.list')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">All Products</a>
                            <button onClick={() => setShowCreate(!showCreate)} className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700">New Feed</button>
                        </div>
                    </div>

                    {/* Stats */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <StatCard label="Total Products" value={stats?.total || 0} />
                        <StatCard label="Approved" value={stats?.approved || 0} color="text-green-600" />
                        <StatCard label="Disapproved" value={stats?.disapproved || 0} color="text-red-600" />
                        <StatCard label="Out of Stock" value={stats?.out_of_stock || 0} color="text-yellow-600" />
                    </div>

                    {/* Create Form */}
                    {showCreate && (
                        <form onSubmit={handleCreate} className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
                            <h3 className="text-sm font-semibold text-gray-900 mb-4">Connect Product Feed</h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-medium text-gray-700 mb-1">Feed Name</label>
                                    <input type="text" value={form.feed_name} onChange={e => setForm({...form, feed_name: e.target.value})} required className="w-full rounded-lg border-gray-300 text-sm" placeholder="My Product Feed" />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-gray-700 mb-1">Merchant Center ID</label>
                                    <input type="text" value={form.merchant_id} onChange={e => setForm({...form, merchant_id: e.target.value})} required className="w-full rounded-lg border-gray-300 text-sm" placeholder="123456789" />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-gray-700 mb-1">Source Type</label>
                                    <select value={form.source_type} onChange={e => setForm({...form, source_type: e.target.value})} className="w-full rounded-lg border-gray-300 text-sm">
                                        <option value="api">Merchant Center API</option>
                                        <option value="url">Feed URL</option>
                                        <option value="shopify">Shopify</option>
                                        <option value="woocommerce">WooCommerce</option>
                                        <option value="manual">Manual</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-gray-700 mb-1">Sync Frequency</label>
                                    <select value={form.sync_frequency} onChange={e => setForm({...form, sync_frequency: e.target.value})} className="w-full rounded-lg border-gray-300 text-sm">
                                        <option value="hourly">Hourly</option>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                    </select>
                                </div>
                            </div>
                            <div className="mt-4 flex justify-end gap-2">
                                <button type="button" onClick={() => setShowCreate(false)} className="px-4 py-2 text-sm text-gray-600">Cancel</button>
                                <button type="submit" disabled={saving} className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 disabled:opacity-50">{saving ? 'Creating...' : 'Create Feed'}</button>
                            </div>
                        </form>
                    )}

                    {/* Feeds */}
                    {feeds.length > 0 ? (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {feeds.map(f => <FeedCard key={f.id} feed={f} />)}
                        </div>
                    ) : (
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <h3 className="text-sm font-medium text-gray-900">No product feeds</h3>
                            <p className="mt-1 text-sm text-gray-500">Connect your Merchant Center to manage Shopping campaigns.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
