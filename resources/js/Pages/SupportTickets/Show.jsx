import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormErrorSummary from '@/Components/FormErrorSummary';
import { brandTint } from '@/Components/Marketing/Hero';

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
    const { data, setData, post, processing, errors, reset } = useForm({ message: '' });
    const reply = (event) => {
        event.preventDefault();
        post(route('support-tickets.reply', ticket.id), { preserveScroll: true, onSuccess: () => reset() });
    };
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-3">
                    <Link href={route('support-tickets.index')} aria-label="Back to support tickets" className="text-gray-500 hover:text-gray-700">
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
                <div className="max-w-3xl mx-auto">
                    <div className="bg-white rounded-lg shadow-md overflow-hidden">
                        {/* Header */}
                        <div className="px-6 py-4 border-b border-gray-200">
                            <div className="flex flex-wrap items-start justify-between gap-3">
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
                            <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-gray-500">
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
                        {ticket.transcript?.[0]?.text !== ticket.description && <div className="px-6 py-5">
                            <h4 className="text-sm font-medium text-gray-700 mb-2">Your Message</h4>
                            <div className="bg-gray-50 rounded-lg p-4 text-sm text-gray-800 whitespace-pre-wrap">
                                {ticket.description}
                            </div>
                        </div>}

                        {ticket.transcript?.length > 0 && (
                            <div className="space-y-3 border-t border-gray-200 px-6 py-5" aria-label="Conversation history">
                                {ticket.transcript.map((turn, index) => (
                                    <div key={index} className={`rounded-lg p-4 ${turn.role === 'customer' ? 'bg-gray-50' : 'bg-brand-tint-10'}`}>
                                        <p className="mb-1 text-xs font-semibold text-gray-600">
                                            {turn.role === 'customer' ? 'You' : turn.role === 'admin' ? `Support${turn.name ? ` · ${turn.name}` : ''}` : 'AI assistant'}
                                            {turn.at && <span className="ml-2 font-normal">{new Date(turn.at).toLocaleString()}</span>}
                                        </p>
                                        <p className="whitespace-pre-wrap break-words text-sm text-gray-800">{turn.text}</p>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Admin Response */}
                        {ticket.admin_response && !ticket.transcript?.some(turn => turn.role === 'admin') && (
                            <div className="px-6 py-5 border-t border-gray-200" style={{ backgroundColor: brandTint(10) }}>
                                <h4 className="text-sm font-medium text-brand-darker mb-2">
                                    Response from Support
                                    {ticket.assignee && <span className="font-normal text-brand-dark"> — {ticket.assignee.name}</span>}
                                </h4>
                                <div className="bg-white rounded-lg p-4 text-sm text-gray-800 whitespace-pre-wrap border" style={{ borderColor: brandTint(20) }}>
                                    {ticket.admin_response}
                                </div>
                                {ticket.resolved_at && (
                                    <p className="mt-3 text-xs text-brand-dark">
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
                        <form onSubmit={reply} className="space-y-3 border-t border-gray-200 px-6 py-5">
                            <FormErrorSummary errors={errors} labels={{ message: 'Your reply' }} />
                            <label htmlFor="message" className="block text-sm font-medium text-gray-700">Reply to the team</label>
                            <textarea id="message" value={data.message} onChange={event => setData('message', event.target.value)} maxLength={5000} required rows={4} aria-invalid={Boolean(errors.message)} className="w-full rounded-lg border-gray-300 text-sm" />
                            <p className="text-xs text-gray-500">A reply reopens a resolved or closed ticket.</p>
                            <button disabled={processing} className="rounded-lg bg-brand-dark px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{processing ? 'Saving reply…' : 'Send reply'}</button>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
