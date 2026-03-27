import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SideNav from '../SideNav';

const priorityColors = {
    low: 'bg-gray-100 text-gray-700',
    normal: 'bg-blue-100 text-blue-700',
    high: 'bg-orange-100 text-orange-700',
    urgent: 'bg-red-100 text-red-700',
};

const statusColors = {
    open: 'bg-yellow-100 text-yellow-800',
    in_progress: 'bg-blue-100 text-blue-800',
    resolved: 'bg-green-100 text-green-800',
    closed: 'bg-gray-100 text-gray-800',
};

const statusLabels = {
    open: 'Open',
    in_progress: 'In Progress',
    resolved: 'Resolved',
    closed: 'Closed',
};

export default function Index({ tickets, stats, filters }) {
    const applyFilter = (key, value) => {
        router.get(route('admin.support-tickets.index'), {
            ...filters,
            [key]: value || undefined,
        }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Admin - Support Tickets
                </h2>
            }
        >
            <Head title="Admin - Support Tickets" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    {/* Stats */}
                    <div className="grid grid-cols-4 gap-4 mb-6">
                        {[
                            { label: 'Open', count: stats.open, color: 'text-yellow-600 bg-yellow-50', key: 'open' },
                            { label: 'In Progress', count: stats.in_progress, color: 'text-blue-600 bg-blue-50', key: 'in_progress' },
                            { label: 'Resolved', count: stats.resolved, color: 'text-green-600 bg-green-50', key: 'resolved' },
                            { label: 'Closed', count: stats.closed, color: 'text-gray-600 bg-gray-50', key: 'closed' },
                        ].map((stat) => (
                            <button
                                key={stat.key}
                                onClick={() => applyFilter('status', filters.status === stat.key ? '' : stat.key)}
                                className={`rounded-lg p-4 text-center transition-all ${stat.color} ${
                                    filters.status === stat.key ? 'ring-2 ring-indigo-500' : ''
                                }`}
                            >
                                <div className="text-2xl font-bold">{stat.count}</div>
                                <div className="text-sm font-medium mt-1">{stat.label}</div>
                            </button>
                        ))}
                    </div>

                    {/* Filters */}
                    <div className="flex items-center gap-3 mb-4">
                        <select
                            value={filters.priority || ''}
                            onChange={(e) => applyFilter('priority', e.target.value)}
                            className="rounded-md border-gray-300 text-sm"
                        >
                            <option value="">All Priorities</option>
                            <option value="urgent">Urgent</option>
                            <option value="high">High</option>
                            <option value="normal">Normal</option>
                            <option value="low">Low</option>
                        </select>
                        {(filters.status || filters.priority) && (
                            <button
                                onClick={() => router.get(route('admin.support-tickets.index'))}
                                className="text-sm text-gray-500 hover:text-gray-700"
                            >
                                Clear filters
                            </button>
                        )}
                    </div>

                    {/* Tickets Table */}
                    <div className="bg-white rounded-lg shadow-md overflow-hidden">
                        {tickets.data.length === 0 ? (
                            <div className="p-12 text-center text-gray-500">
                                No tickets found.
                            </div>
                        ) : (
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {tickets.data.map((ticket) => (
                                        <tr
                                            key={ticket.id}
                                            onClick={() => router.visit(route('admin.support-tickets.show', ticket.id))}
                                            className="hover:bg-gray-50 cursor-pointer"
                                        >
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                #{ticket.id}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-900 max-w-xs truncate">
                                                {ticket.subject}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <div>{ticket.user?.name}</div>
                                                <div className="text-xs text-gray-400">{ticket.user?.email}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${priorityColors[ticket.priority]}`}>
                                                    {ticket.priority}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${statusColors[ticket.status]}`}>
                                                    {statusLabels[ticket.status]}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 capitalize">
                                                {ticket.category || '—'}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {new Date(ticket.created_at).toLocaleDateString('en-US', {
                                                    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                                                })}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}

                        {/* Pagination */}
                        {tickets.last_page > 1 && (
                            <div className="px-6 py-3 border-t border-gray-200 flex items-center justify-between">
                                <p className="text-sm text-gray-500">
                                    Showing {tickets.from}–{tickets.to} of {tickets.total} tickets
                                </p>
                                <div className="flex gap-2">
                                    {tickets.links.map((link, i) => (
                                        <button
                                            key={i}
                                            disabled={!link.url}
                                            onClick={() => link.url && router.visit(link.url)}
                                            className={`px-3 py-1 text-sm rounded ${
                                                link.active
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'bg-white text-gray-600 border hover:bg-gray-50'
                                            } ${!link.url ? 'opacity-50 cursor-not-allowed' : ''}`}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
