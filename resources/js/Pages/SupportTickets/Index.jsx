import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

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

export default function Index({ tickets }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Support Tickets
                    </h2>
                    <Link
                        href={route('support-tickets.create')}
                        className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors"
                    >
                        <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        New Ticket
                    </Link>
                </div>
            }
        >
            <Head title="Support Tickets" />

            <div className="py-12">
                <div className="max-w-4xl mx-auto sm:px-6 lg:px-8">
                    {tickets.length === 0 ? (
                        <div className="bg-white rounded-lg shadow-md p-6 sm:p-12 text-center">
                            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                            </svg>
                            <h3 className="mt-4 text-lg font-medium text-gray-900">No support tickets</h3>
                            <p className="mt-2 text-sm text-gray-500">
                                Need help? Create a support ticket and we'll get back to you.
                            </p>
                            <Link
                                href={route('support-tickets.create')}
                                className="mt-6 inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700"
                            >
                                Create Ticket
                            </Link>
                        </div>
                    ) : (
                        <div className="bg-white rounded-lg shadow-md overflow-hidden">
                            <div className="divide-y divide-gray-200">
                                {tickets.map((ticket) => (
                                    <Link
                                        key={ticket.id}
                                        href={route('support-tickets.show', ticket.id)}
                                        className="block p-6 hover:bg-gray-50 transition-colors"
                                    >
                                        <div className="flex items-start justify-between">
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-3">
                                                    <h3 className="text-sm font-semibold text-gray-900 truncate">
                                                        #{ticket.id} — {ticket.subject}
                                                    </h3>
                                                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${statusColors[ticket.status]}`}>
                                                        {statusLabels[ticket.status]}
                                                    </span>
                                                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${priorityColors[ticket.priority]}`}>
                                                        {ticket.priority}
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-sm text-gray-500 line-clamp-1">
                                                    {ticket.description}
                                                </p>
                                                <p className="mt-2 text-xs text-gray-400">
                                                    {new Date(ticket.created_at).toLocaleDateString('en-US', {
                                                        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                                                    })}
                                                    {ticket.category && <> · {ticket.category}</>}
                                                </p>
                                            </div>
                                            <svg className="w-5 h-5 text-gray-400 ml-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                            </svg>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
