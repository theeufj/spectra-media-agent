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

export default function Show({ ticket }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-3">
                    <Link href={route('support-tickets.index')} className="text-gray-500 hover:text-gray-700">
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
            <Head title={`Ticket #${ticket.id}`} />

            <div className="py-12">
                <div className="max-w-3xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white rounded-lg shadow-md overflow-hidden">
                        {/* Header */}
                        <div className="px-6 py-4 border-b border-gray-200">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold text-gray-900">{ticket.subject}</h3>
                                <div className="flex items-center gap-2">
                                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${statusColors[ticket.status]}`}>
                                        {statusLabels[ticket.status]}
                                    </span>
                                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${priorityColors[ticket.priority]}`}>
                                        {ticket.priority}
                                    </span>
                                </div>
                            </div>
                            <div className="mt-2 flex items-center gap-4 text-sm text-gray-500">
                                <span>
                                    Submitted {new Date(ticket.created_at).toLocaleDateString('en-US', {
                                        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                                    })}
                                </span>
                                {ticket.category && (
                                    <span className="capitalize">· {ticket.category}</span>
                                )}
                            </div>
                        </div>

                        {/* Description */}
                        <div className="px-6 py-5">
                            <h4 className="text-sm font-medium text-gray-700 mb-2">Your Message</h4>
                            <div className="bg-gray-50 rounded-lg p-4 text-sm text-gray-800 whitespace-pre-wrap">
                                {ticket.description}
                            </div>
                        </div>

                        {/* Admin Response */}
                        {ticket.admin_response && (
                            <div className="px-6 py-5 border-t border-gray-200 bg-flame-orange-50">
                                <h4 className="text-sm font-medium text-flame-orange-900 mb-2">
                                    Response from Support
                                    {ticket.assignee && <span className="font-normal text-flame-orange-600"> — {ticket.assignee.name}</span>}
                                </h4>
                                <div className="bg-white rounded-lg p-4 text-sm text-gray-800 whitespace-pre-wrap border border-flame-orange-100">
                                    {ticket.admin_response}
                                </div>
                                {ticket.resolved_at && (
                                    <p className="mt-3 text-xs text-flame-orange-600">
                                        Resolved on {new Date(ticket.resolved_at).toLocaleDateString('en-US', {
                                            year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                                        })}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* No response yet */}
                        {!ticket.admin_response && ticket.status !== 'closed' && (
                            <div className="px-6 py-5 border-t border-gray-200">
                                <div className="flex items-center gap-3 text-sm text-gray-500">
                                    <svg className="w-5 h-5 text-yellow-500 animate-pulse" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clipRule="evenodd" />
                                    </svg>
                                    <span>Awaiting response from our support team. We'll get back to you soon.</span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
