import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import SideNav from './SideNav';

export default function FeatureFlags({ auth, features = [], users = [] }) {
    const [filter, setFilter] = useState('');

    const filteredUsers = users.filter(
        (u) =>
            u.name.toLowerCase().includes(filter.toLowerCase()) ||
            u.email.toLowerCase().includes(filter.toLowerCase())
    );

    const handleToggle = (featureName, userId, currentValue) => {
        router.post(
            route('admin.feature-flags.toggle', featureName),
            { user_id: userId, active: !currentValue },
            { preserveScroll: true }
        );
    };

    const handleGlobalToggle = (featureName, activate) => {
        router.post(
            route('admin.feature-flags.toggle', featureName),
            { user_id: null, active: activate },
            { preserveScroll: true }
        );
    };

    const handlePurge = (featureName) => {
        router.post(route('admin.feature-flags.purge', featureName), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Feature Flags" />
            <div className="flex">
                <SideNav />
                <div className="flex-1 py-8 px-6 lg:px-10">
                    <div className="mb-6">
                        <h1 className="text-2xl font-bold text-gray-900">Feature Flags</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Manage feature access per-user or globally. Features are defined in <code className="text-xs bg-gray-100 px-1 py-0.5 rounded">app/Features/</code>.
                        </p>
                    </div>

                    {features.length === 0 ? (
                        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
                            <p className="text-sm text-gray-500">No feature classes found in <code>app/Features/</code>.</p>
                        </div>
                    ) : (
                        <>
                            {/* Feature summary cards */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                                {features.map((f) => {
                                    const activeCount = users.filter((u) => u.flags[f.class]).length;
                                    return (
                                        <div key={f.class} className="bg-white rounded-lg border border-gray-200 p-4">
                                            <div className="flex items-center justify-between mb-2">
                                                <h3 className="text-sm font-semibold text-gray-900">{f.name}</h3>
                                                <span className="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">
                                                    {activeCount}/{users.length} users
                                                </span>
                                            </div>
                                            <p className="text-xs text-gray-400 mb-3 font-mono">{f.class}</p>
                                            <div className="flex items-center gap-2">
                                                <button
                                                    onClick={() => handleGlobalToggle(f.name, true)}
                                                    className="text-[11px] px-2.5 py-1 bg-green-50 text-green-700 rounded-md hover:bg-green-100 font-medium transition"
                                                >
                                                    Activate All
                                                </button>
                                                <button
                                                    onClick={() => handleGlobalToggle(f.name, false)}
                                                    className="text-[11px] px-2.5 py-1 bg-red-50 text-red-600 rounded-md hover:bg-red-100 font-medium transition"
                                                >
                                                    Deactivate All
                                                </button>
                                                <button
                                                    onClick={() => handlePurge(f.name)}
                                                    className="text-[11px] px-2.5 py-1 bg-gray-50 text-gray-500 rounded-md hover:bg-gray-100 font-medium transition ml-auto"
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
                                    <h2 className="text-sm font-semibold text-gray-900">Per-User Feature Access</h2>
                                    <input
                                        type="text"
                                        placeholder="Filter users..."
                                        value={filter}
                                        onChange={(e) => setFilter(e.target.value)}
                                        className="text-xs border border-gray-200 rounded-md px-3 py-1.5 w-56 focus:ring-1 focus:ring-flame-orange-500 focus:border-flame-orange-500"
                                    />
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-2.5 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">User</th>
                                                <th className="px-4 py-2.5 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">Plan</th>
                                                {features.map((f) => (
                                                    <th key={f.class} className="px-4 py-2.5 text-center text-[10px] font-medium text-gray-500 uppercase tracking-wider">
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
                                                            <p className="text-[10px] text-gray-400">{user.email}</p>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-2.5">
                                                        <span className="text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">
                                                            {user.plan || 'Free'}
                                                        </span>
                                                    </td>
                                                    {features.map((f) => {
                                                        const isActive = user.flags[f.class];
                                                        return (
                                                            <td key={f.class} className="px-4 py-2.5 text-center">
                                                                <button
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
                                    <div className="px-4 py-6 text-center text-xs text-gray-400">No users match the filter.</div>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
