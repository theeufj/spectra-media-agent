import React from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        subject: '',
        description: '',
        priority: 'normal',
        category: 'general',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('support-tickets.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Submit a Support Ticket
                </h2>
            }
        >
            <Head title="New Support Ticket" />

            <div className="py-12">
                <div className="max-w-2xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white rounded-lg shadow-md overflow-hidden">
                        <div className="px-6 py-4 bg-gradient-to-r from-flame-orange-600 to-purple-600">
                            <h3 className="text-lg font-semibold text-white">How can we help?</h3>
                            <p className="text-sm text-flame-orange-100 mt-1">Describe your issue and we'll get back to you as soon as possible.</p>
                        </div>

                        <form onSubmit={handleSubmit} className="p-6 space-y-6">
                            <div>
                                <label htmlFor="category" className="block text-sm font-medium text-gray-700 mb-1">
                                    Category
                                </label>
                                <select
                                    id="category"
                                    value={data.category}
                                    onChange={(e) => setData('category', e.target.value)}
                                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                                >
                                    <option value="general">General</option>
                                    <option value="billing">Billing</option>
                                    <option value="technical">Technical</option>
                                    <option value="campaign">Campaign</option>
                                </select>
                                {errors.category && <p className="mt-1 text-sm text-red-600">{errors.category}</p>}
                            </div>

                            <div>
                                <label htmlFor="subject" className="block text-sm font-medium text-gray-700 mb-1">
                                    Subject
                                </label>
                                <input
                                    id="subject"
                                    type="text"
                                    value={data.subject}
                                    onChange={(e) => setData('subject', e.target.value)}
                                    placeholder="Brief summary of your issue"
                                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                                    maxLength={255}
                                />
                                {errors.subject && <p className="mt-1 text-sm text-red-600">{errors.subject}</p>}
                            </div>

                            <div>
                                <label htmlFor="priority" className="block text-sm font-medium text-gray-700 mb-1">
                                    Priority
                                </label>
                                <select
                                    id="priority"
                                    value={data.priority}
                                    onChange={(e) => setData('priority', e.target.value)}
                                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                                >
                                    <option value="low">Low — General question</option>
                                    <option value="normal">Normal — Something isn't working right</option>
                                    <option value="high">High — Significant impact on my campaigns</option>
                                    <option value="urgent">Urgent — Critical issue, campaigns down</option>
                                </select>
                                {errors.priority && <p className="mt-1 text-sm text-red-600">{errors.priority}</p>}
                            </div>

                            <div>
                                <label htmlFor="description" className="block text-sm font-medium text-gray-700 mb-1">
                                    Description
                                </label>
                                <textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Please describe your issue in detail. Include any relevant campaign names, error messages, or steps to reproduce."
                                    rows={6}
                                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                                    maxLength={5000}
                                />
                                <p className="mt-1 text-xs text-gray-400">{data.description.length}/5000</p>
                                {errors.description && <p className="mt-1 text-sm text-red-600">{errors.description}</p>}
                            </div>

                            <div className="flex items-center justify-between pt-4 border-t">
                                <Link
                                    href={route('support-tickets.index')}
                                    className="text-sm text-gray-600 hover:text-gray-900"
                                >
                                    &larr; Back to tickets
                                </Link>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center px-6 py-2.5 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 disabled:opacity-50 transition-colors"
                                >
                                    {processing ? 'Submitting...' : 'Submit Ticket'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
