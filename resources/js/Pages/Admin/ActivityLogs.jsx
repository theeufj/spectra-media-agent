import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, Link } from '@inertiajs/react';
import SideNav from './SideNav';

const ActionIcon = ({ icon, color }) => {
    const colors = {
        green: 'bg-green-100 text-green-600',
        red: 'bg-red-100 text-red-600',
        yellow: 'bg-yellow-100 text-yellow-600',
        blue: 'bg-blue-100 text-blue-600',
        gray: 'bg-gray-100 text-gray-600',
        orange: 'bg-orange-100 text-orange-600',
    };

    const icons = {
        key: (
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
            </svg>
        ),
        'user-circle': (
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
        user: (
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
        ),
        megaphone: (
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
            </svg>
        ),
        building: (
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
        ),
        'credit-card': (
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
            </svg>
        ),
        cog: (
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            </svg>
        ),
        'information-circle': (
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
    };

    return (
        <div className={`p-2 rounded-full ${colors[color] || colors.blue}`}>
            {icons[icon] || icons['information-circle']}
        </div>
    );
};

const LogRow = ({ log }) => {
    const [expanded, setExpanded] = useState(false);

    return (
        <>
            <tr 
                className="hover:bg-gray-50 cursor-pointer"
                onClick={() => setExpanded(!expanded)}
            >
                <td className="px-6 py-4 whitespace-nowrap">
                    <div className="flex items-center">
                        <ActionIcon icon={log.actionIcon} color={log.actionColor} />
                        <div className="ml-3">
                            <p className="text-sm font-medium text-gray-900">{log.actionLabel}</p>
                            <p className="text-xs text-gray-500">{log.action}</p>
                        </div>
                    </div>
                </td>
                <td className="px-6 py-4">
                    <p className="text-sm text-gray-900 max-w-md truncate">{log.description}</p>
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                    {log.user && (
                        <div>
                            <p className="text-sm font-medium text-gray-900">{log.user.name}</p>
                            <p className="text-xs text-gray-500">{log.user.email}</p>
                        </div>
                    )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                    <p className="text-sm text-gray-500">{log.created_at_human}</p>
                    <p className="text-xs text-gray-400">{log.created_at}</p>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right">
                    <svg 
                        className={`w-5 h-5 text-gray-400 transform transition-transform ${expanded ? 'rotate-180' : ''}`}
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                    </svg>
                </td>
            </tr>
            {expanded && (
                <tr className="bg-gray-50">
                    <td colSpan={5} className="px-6 py-4">
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <p className="text-gray-500 text-xs uppercase">IP Address</p>
                                <p className="font-mono text-gray-900">{log.ip_address || 'N/A'}</p>
                            </div>
                            <div>
                                <p className="text-gray-500 text-xs uppercase">Subject</p>
                                <p className="text-gray-900">
                                    {log.subject_type ? `${log.subject_type} #${log.subject_id}` : 'N/A'}
                                </p>
                            </div>
                            <div className="col-span-2">
                                <p className="text-gray-500 text-xs uppercase">User Agent</p>
                                <p className="text-gray-900 text-xs truncate">{log.user_agent || 'N/A'}</p>
                            </div>
                            {log.properties && Object.keys(log.properties).length > 0 && (
                                <div className="col-span-4">
                                    <p className="text-gray-500 text-xs uppercase mb-1">Properties</p>
                                    <pre className="bg-gray-100 p-2 rounded text-xs overflow-x-auto">
                                        {JSON.stringify(log.properties, null, 2)}
                                    </pre>
                                </div>
                            )}
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
};

export default function ActivityLogs({ logs, actionTypes, recentUsers, filters }) {
    const [localFilters, setLocalFilters] = useState(filters);

    const applyFilters = () => {
        router.get(route('admin.activity.index'), localFilters, { preserveState: true });
    };

    const clearFilters = () => {
        setLocalFilters({ action: '', user_id: '', from: '', to: '', search: '' });
        router.get(route('admin.activity.index'));
    };

    const exportLogs = () => {
        window.location.href = route('admin.activity.export', localFilters);
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Activity Logs</h2>}
        >
            <Head title="Activity Logs" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-7xl mx-auto">
                        {/* Filters */}
                        <div className="bg-white rounded-lg shadow p-6 mb-6">
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                    <input
                                        type="text"
                                        value={localFilters.search || ''}
                                        onChange={(e) => setLocalFilters({ ...localFilters, search: e.target.value })}
                                        placeholder="Search description, email..."
                                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Action Type</label>
                                    <select
                                        value={localFilters.action || ''}
                                        onChange={(e) => setLocalFilters({ ...localFilters, action: e.target.value })}
                                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="">All actions</option>
                                        {actionTypes.map((action) => (
                                            <option key={action} value={action}>{action}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">User</label>
                                    <select
                                        value={localFilters.user_id || ''}
                                        onChange={(e) => setLocalFilters({ ...localFilters, user_id: e.target.value })}
                                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="">All users</option>
                                        {recentUsers.map((user) => (
                                            <option key={user.user_id} value={user.user_id}>
                                                {user.user_name} ({user.user_email})
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                                    <input
                                        type="date"
                                        value={localFilters.from || ''}
                                        onChange={(e) => setLocalFilters({ ...localFilters, from: e.target.value })}
                                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                                    <input
                                        type="date"
                                        value={localFilters.to || ''}
                                        onChange={(e) => setLocalFilters({ ...localFilters, to: e.target.value })}
                                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    />
                                </div>
                            </div>
                            <div className="flex justify-between items-center mt-4">
                                <div className="flex space-x-2">
                                    <button
                                        onClick={applyFilters}
                                        className="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700"
                                    >
                                        Apply Filters
                                    </button>
                                    <button
                                        onClick={clearFilters}
                                        className="px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded-md hover:bg-gray-300"
                                    >
                                        Clear
                                    </button>
                                </div>
                                <button
                                    onClick={exportLogs}
                                    className="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-md hover:bg-gray-50"
                                >
                                    Export CSV
                                </button>
                            </div>
                        </div>

                        {/* Logs Table */}
                        <div className="bg-white rounded-lg shadow overflow-hidden">
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                            <th className="px-6 py-3"></th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {logs.data.map((log) => (
                                            <LogRow key={log.id} log={log} />
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination */}
                            {logs.last_page > 1 && (
                                <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                                    <p className="text-sm text-gray-500">
                                        Showing {logs.from} to {logs.to} of {logs.total} results
                                    </p>
                                    <div className="flex space-x-2">
                                        {logs.links.map((link, index) => (
                                            <Link
                                                key={index}
                                                href={link.url || '#'}
                                                className={`px-3 py-1 text-sm rounded ${
                                                    link.active 
                                                        ? 'bg-indigo-600 text-white' 
                                                        : link.url 
                                                            ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' 
                                                            : 'bg-gray-50 text-gray-400 cursor-not-allowed'
                                                }`}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ))}
                                    </div>
                                </div>
                            )}

                            {logs.data.length === 0 && (
                                <div className="px-6 py-12 text-center text-gray-500">
                                    No activity logs found
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
