import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const STATUS_STYLES = {
    generating: { bg: 'bg-yellow-100', text: 'text-yellow-800', label: 'Generating...' },
    ready: { bg: 'bg-green-100', text: 'text-green-800', label: 'Ready' },
    failed: { bg: 'bg-red-100', text: 'text-red-800', label: 'Failed' },
};

export default function Index({ proposals }) {
    return (
        <AuthenticatedLayout>
            <Head title="Proposals" />

            <div className="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <div className="flex justify-between items-center mb-8">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Proposals</h1>
                        <p className="text-gray-500 mt-1">AI-generated advertising proposals for prospective clients.</p>
                    </div>
                    <Link
                        href={route('proposals.create')}
                        className="inline-flex items-center px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium shadow-md"
                    >
                        <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        New Proposal
                    </Link>
                </div>

                {proposals.length === 0 ? (
                    <div className="bg-white rounded-lg shadow-md p-12 text-center">
                        <svg className="mx-auto h-16 w-16 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <h3 className="mt-4 text-lg font-medium text-gray-900">No proposals yet</h3>
                        <p className="mt-2 text-sm text-gray-500">
                            Create your first AI-generated proposal to wow prospective clients.
                        </p>
                        <Link
                            href={route('proposals.create')}
                            className="mt-6 inline-flex items-center px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium"
                        >
                            Create Proposal
                        </Link>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        {proposals.map((proposal) => {
                            const status = STATUS_STYLES[proposal.status] || STATUS_STYLES.generating;
                            return (
                                <Link
                                    key={proposal.id}
                                    href={route('proposals.show', proposal.id)}
                                    className="bg-white rounded-lg shadow-md hover:shadow-lg transition-shadow overflow-hidden group"
                                >
                                    <div className="bg-gradient-to-r from-indigo-600 to-indigo-700 px-6 py-4">
                                        <h3 className="text-lg font-semibold text-white truncate group-hover:underline">
                                            {proposal.client_name}
                                        </h3>
                                        <p className="text-indigo-200 text-sm">{proposal.industry || 'General'}</p>
                                    </div>
                                    <div className="p-6">
                                        <div className="flex items-center justify-between mb-3">
                                            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${status.bg} ${status.text}`}>
                                                {proposal.status === 'generating' && (
                                                    <svg className="animate-spin -ml-0.5 mr-1.5 h-3 w-3" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                    </svg>
                                                )}
                                                {status.label}
                                            </span>
                                            {proposal.budget && (
                                                <span className="text-sm font-semibold text-gray-700">
                                                    ${Number(proposal.budget).toLocaleString()}/mo
                                                </span>
                                            )}
                                        </div>
                                        {proposal.platforms && (
                                            <div className="flex flex-wrap gap-1.5 mb-3">
                                                {proposal.platforms.map((p) => (
                                                    <span key={p} className="inline-block bg-indigo-50 text-indigo-700 text-xs px-2 py-0.5 rounded">
                                                        {p}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                        <p className="text-xs text-gray-400">
                                            Created {new Date(proposal.created_at).toLocaleDateString()}
                                        </p>
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
