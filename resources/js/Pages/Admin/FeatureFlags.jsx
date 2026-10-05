import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import SideNav from './SideNav';
import ConfirmationModal from '@/Components/ConfirmationModal';

export default function FeatureFlags({ auth, features = [], users = [], customerFeatures = [], customers = [] }) {
    const [filter, setFilter] = useState('');
    const [scope, setScope] = useState(customerFeatures.length > 0 ? 'customer' : 'user');
    const [busy, setBusy] = useState(false);
    const [confirmation, setConfirmation] = useState(null);
    const displayedFeatures = scope === 'customer' ? customerFeatures : features;
    const records = scope === 'customer' ? customers : users;

    const filteredUsers = records.filter(
        (u) =>
            u.name.toLowerCase().includes(filter.toLowerCase()) ||
            (u.email || '').toLowerCase().includes(filter.toLowerCase())
    );

    const post = (url, data) => {
        setBusy(true);
        router.post(url, data, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const handleToggle = (featureName, id, currentValue) => {
        post(route('admin.feature-flags.toggle', featureName), {
            [scope === 'customer' ? 'customer_id' : 'user_id']: id,
            active: !currentValue,
        });
    };

    const handleGlobalToggle = (featureName, activate) => {
        setConfirmation({
            title: `${activate ? 'Activate' : 'Deactivate'} ${featureName} for everyone?`,
            message: `This changes ${featureName} for every existing ${scope === 'customer' ? 'customer account' : 'user'}. Review the scope before applying.`,
            onConfirm: () => post(route('admin.feature-flags.toggle', featureName), { active: activate }),
        });
    };

    const handlePurge = (featureName) => {
        setConfirmation({
            title: `Reset ${featureName} overrides?`,
            message: 'Stored overrides will be removed. The next feature check will use its default eligibility rules.',
            onConfirm: () => post(route('admin.feature-flags.purge', featureName), { confirmed: true }),
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} contained={false}>
            <Head title="Feature Flags" />
            <div className="flex flex-col lg:flex-row">
                <SideNav />
                <div className="min-w-0 flex-1 py-8 px-6 lg:px-10">
                    <div className="mb-6">
                        <h1 className="text-2xl font-bold text-gray-900">Feature Flags</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Manage automation per customer account and feature access per user. Global actions affect every existing record in the selected scope.
                        </p>
                    </div>

                    <div className="mb-6 flex flex-wrap gap-2" aria-label="Feature scope">
                        {[['customer', 'Customer automation'], ['user', 'User access']].map(([value, label]) => (
                            <button key={value} type="button" aria-pressed={scope === value} disabled={busy} onClick={() => { setScope(value); setFilter(''); }} className={`rounded-md border px-4 py-2 text-sm ${scope === value ? 'bg-brand-dark text-white' : 'bg-white text-gray-700'}`}>{label}</button>
                        ))}
                    </div>
                    {displayedFeatures.length === 0 ? (
                        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
                            <p className="text-sm text-gray-500">No features are configured for this scope.</p>
                        </div>
                    ) : (
                        <>
                            {/* Feature summary cards */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                                {displayedFeatures.map((f) => {
                                    const activeCount = records.filter((u) => u.flags[f.class]).length;
                                    return (
                                        <div key={f.class} className="bg-white rounded-lg border border-gray-200 p-4">
                                            <div className="flex items-center justify-between mb-2">
                                                <h3 className="text-sm font-semibold text-gray-900">{f.name}</h3>
                                                <span className="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">
                                                    {activeCount}/{records.length} {scope === 'customer' ? 'accounts' : 'users'}
                                                </span>
                                            </div>
                                            <p className="text-xs text-gray-500 mb-3 font-mono">{f.class}</p>
                                            <div className="flex items-center gap-2">
                                                <button disabled={busy}
                                                    onClick={() => handleGlobalToggle(f.name, true)}
                                                    className="text-xs px-2.5 py-1 bg-green-50 text-green-700 rounded-md hover:bg-green-100 font-medium transition"
                                                >
                                                    Activate All
                                                </button>
                                                <button disabled={busy}
                                                    onClick={() => handleGlobalToggle(f.name, false)}
                                                    className="text-xs px-2.5 py-1 bg-red-50 text-red-600 rounded-md hover:bg-red-100 font-medium transition"
                                                >
                                                    Deactivate All
                                                </button>
                                                <button disabled={busy}
                                                    onClick={() => handlePurge(f.name)}
                                                    className="text-xs px-2.5 py-1 bg-gray-50 text-gray-500 rounded-md hover:bg-gray-100 font-medium transition ml-auto"
                                                >
                                                    Purge Cache
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            {/* Per-user table */}
                            <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                                <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                                    <h2 className="text-sm font-semibold text-gray-900">{scope === 'customer' ? 'Customer Automation' : 'User Feature Access'}</h2>
                                    <input
                                        type="text"
                                        aria-label={scope === 'customer' ? 'Filter customer accounts' : 'Filter users'}
                                        placeholder="Filter names or email..."
                                        value={filter}
                                        onChange={(e) => setFilter(e.target.value)}
                                        className="text-xs border border-gray-200 rounded-md px-3 py-1.5 w-56 focus:ring-1 focus:ring-brand-primary focus:border-brand-primary"
                                    />
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{scope === 'customer' ? 'Customer' : 'User'}</th>
                                                {scope === 'user' && <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Plan</th>}
                                                {displayedFeatures.map((f) => (
                                                    <th key={f.class} className="px-4 py-2.5 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        {f.name}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100">
                                            {filteredUsers.map((user) => (
                                                <tr key={user.id} className="hover:bg-gray-50">
                                                    <td className="px-4 py-2.5">
                                                        <div>
                                                            <p className="text-xs font-medium text-gray-900">{user.name}</p>
                                                            <p className="text-xs text-gray-500">{user.email}</p>
                                                        </div>
                                                    </td>
                                                    {scope === 'user' && <td className="px-4 py-2.5">
                                                        <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">
                                                            {user.plan || 'Free'}
                                                        </span>
                                                    </td>}
                                                    {displayedFeatures.map((f) => {
                                                        const isActive = Boolean(user.flags[f.class]);
                                                        return (
                                                            <td key={f.class} className="px-4 py-2.5 text-center">
                                                                <button
                                                                    role="switch"
                                                                    aria-checked={isActive}
                                                                    aria-label={`${f.name} for ${user.name}`}
                                                                    disabled={busy}
                                                                    onClick={() => handleToggle(f.name, user.id, isActive)}
                                                                    className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${
                                                                        isActive ? 'bg-green-500' : 'bg-gray-200'
                                                                    }`}
                                                                >
                                                                    <span
                                                                        className={`inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform ${
                                                                            isActive ? 'translate-x-4' : 'translate-x-1'
                                                                        }`}
                                                                    />
                                                                </button>
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {filteredUsers.length === 0 && (
                                    <div className="px-4 py-6 text-center text-xs text-gray-500">No records match the filter.</div>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
            <ConfirmationModal show={Boolean(confirmation)} onClose={() => setConfirmation(null)} title={confirmation?.title} message={confirmation?.message} processing={busy} onConfirm={() => { confirmation?.onConfirm(); setConfirmation(null); }} />
        </AuthenticatedLayout>
    );
}
