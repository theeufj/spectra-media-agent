import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
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

export default function Show({ ticket }) {
    const { data, setData, put, processing } = useForm({
        status: ticket.status,
        priority: ticket.priority,
        admin_response: ticket.admin_response || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.support-tickets.update', ticket.id), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-3">
                    <Link href={route('admin.support-tickets.index')} className="text-gray-500 hover:text-gray-700">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Ticket #{ticket.id}
                    </h2>
                </div>
            }
        >
            <Head title={`Admin - Ticket #${ticket.id}`} />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-4xl">
                        {/* Ticket Details */}
                        <div className="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                            <div className="px-6 py-4 bg-gradient-to-r from-indigo-600 to-purple-600">
                                <h3 className="text-lg font-semibold text-white">{ticket.subject}</h3>
                                <div className="mt-1 flex items-center gap-3">
                                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${statusColors[ticket.status]}`}>
                                        {statusLabels[ticket.status]}
                                    </span>
                                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${priorityColors[ticket.priority]}`}>
                                        {ticket.priority}
                                    </span>
                                </div>
                            </div>

                            <div className="p-6">
                                {/* Meta */}
                                <div className="grid grid-cols-2 gap-4 mb-6 text-sm">
                                    <div>
                                        <span className="font-medium text-gray-500">Submitted by:</span>
                                        <p className="mt-1 text-gray-900">
                                            {ticket.user?.name} ({ticket.user?.email})
                                        </p>
                                    </div>
                                    <div>
                                        <span className="font-medium text-gray-500">Customer:</span>
                                        <p className="mt-1 text-gray-900">{ticket.customer?.name || '—'}</p>
                                    </div>
                                    <div>
                                        <span className="font-medium text-gray-500">Category:</span>
                                        <p className="mt-1 text-gray-900 capitalize">{ticket.category || 'General'}</p>
                                    </div>
                                    <div>
                                        <span className="font-medium text-gray-500">Created:</span>
                                        <p className="mt-1 text-gray-900">
                                            {new Date(ticket.created_at).toLocaleDateString('en-US', {
                                                year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                                            })}
                                        </p>
                                    </div>
                                    {ticket.resolved_at && (
                                        <div>
                                            <span className="font-medium text-gray-500">Resolved:</span>
                                            <p className="mt-1 text-gray-900">
                                                {new Date(ticket.resolved_at).toLocaleDateString('en-US', {
                                                    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                                                })}
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {/* User's Description */}
                                <div>
                                    <h4 className="text-sm font-medium text-gray-700 mb-2">User's Message</h4>
                                    <div className="bg-gray-50 rounded-lg p-4 text-sm text-gray-800 whitespace-pre-wrap">
                                        {ticket.description}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Admin Response Form */}
                        <div className="bg-white rounded-lg shadow-md overflow-hidden">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h3 className="text-lg font-semibold text-gray-900">Manage Ticket</h3>
                            </div>

                            <form onSubmit={handleSubmit} className="p-6 space-y-5">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                        <select
                                            value={data.status}
                                            onChange={(e) => setData('status', e.target.value)}
                                            className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value="open">Open</option>
                                            <option value="in_progress">In Progress</option>
                                            <option value="resolved">Resolved</option>
                                            <option value="closed">Closed</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                                        <select
                                            value={data.priority}
                                            onChange={(e) => setData('priority', e.target.value)}
                                            className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value="low">Low</option>
                                            <option value="normal">Normal</option>
                                            <option value="high">High</option>
                                            <option value="urgent">Urgent</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        Admin Response
                                    </label>
                                    <textarea
                                        value={data.admin_response}
                                        onChange={(e) => setData('admin_response', e.target.value)}
                                        placeholder="Write your response to the user..."
                                        rows={5}
                                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        maxLength={5000}
                                    />
                                </div>

                                <div className="flex justify-end">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex items-center px-6 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
                                    >
                                        {processing ? 'Saving...' : 'Update Ticket'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
