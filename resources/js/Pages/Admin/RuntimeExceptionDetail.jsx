import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import SideNav from './SideNav';

export default function RuntimeExceptionDetail({ auth, exception: ex }) {
    const handleDelete = () => {
        if (confirm('Delete this exception record?')) {
            router.delete(route('admin.runtime-exceptions.destroy', ex.id), {
                onSuccess: () => router.get(route('admin.runtime-exceptions.index')),
            });
        }
    };

    const sourceColor = (src) => {
        switch (src) {
            case 'http': return 'bg-blue-100 text-blue-800';
            case 'queue': return 'bg-purple-100 text-purple-800';
            case 'console': return 'bg-gray-100 text-gray-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    };

    const shortType = (fullType) => {
        if (!fullType) return 'Unknown';
        const parts = fullType.split('\\');
        return parts[parts.length - 1];
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title={`Exception: ${shortType(ex.type)}`} />
            <div className="flex">
                <SideNav />
                <div className="flex-1 p-6">
                    {/* Header */}
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <Link href={route('admin.runtime-exceptions.index')} className="text-sm text-indigo-600 hover:text-indigo-800 mb-2 inline-block">
                                &larr; Back to Exceptions
                            </Link>
                            <h1 className="text-2xl font-bold text-gray-900 font-mono">{shortType(ex.type)}</h1>
                            <div className="flex items-center gap-3 mt-2">
                                <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium ${sourceColor(ex.source)}`}>
                                    {ex.source}
                                </span>
                                <span className="text-sm text-gray-500">
                                    {new Date(ex.created_at).toLocaleString()}
                                </span>
                                <span className="text-sm text-gray-500">
                                    ID: {ex.id}
                                </span>
                            </div>
                        </div>
                        <button onClick={handleDelete} className="px-3 py-2 text-sm font-medium text-red-700 bg-red-50 rounded-md hover:bg-red-100">
                            Delete
                        </button>
                    </div>

                    {/* Message */}
                    <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                        <h3 className="text-sm font-medium text-red-800 mb-1">Message</h3>
                        <p className="text-sm text-red-700 font-mono whitespace-pre-wrap break-words">{ex.message}</p>
                    </div>

                    {/* Details Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div className="bg-white rounded-lg shadow p-4">
                            <h3 className="text-sm font-medium text-gray-500 uppercase mb-3">Exception Details</h3>
                            <dl className="space-y-2">
                                <div>
                                    <dt className="text-xs text-gray-500">Full Type</dt>
                                    <dd className="text-sm font-mono text-gray-900 break-all">{ex.type}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">File</dt>
                                    <dd className="text-sm font-mono text-gray-900 break-all">{ex.file}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-gray-500">Line</dt>
                                    <dd className="text-sm font-mono text-gray-900">{ex.line}</dd>
                                </div>
                                {ex.job_class && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Job Class</dt>
                                        <dd className="text-sm font-mono text-gray-900 break-all">{ex.job_class}</dd>
                                    </div>
                                )}
                            </dl>
                        </div>

                        <div className="bg-white rounded-lg shadow p-4">
                            <h3 className="text-sm font-medium text-gray-500 uppercase mb-3">Request Context</h3>
                            <dl className="space-y-2">
                                {ex.url && (
                                    <div>
                                        <dt className="text-xs text-gray-500">URL</dt>
                                        <dd className="text-sm font-mono text-gray-900 break-all">{ex.method} {ex.url}</dd>
                                    </div>
                                )}
                                {ex.user && (
                                    <div>
                                        <dt className="text-xs text-gray-500">User</dt>
                                        <dd className="text-sm text-gray-900">{ex.user.name} ({ex.user.email})</dd>
                                    </div>
                                )}
                                {ex.customer && (
                                    <div>
                                        <dt className="text-xs text-gray-500">Customer</dt>
                                        <dd className="text-sm text-gray-900">{ex.customer.name}</dd>
                                    </div>
                                )}
                                {!ex.url && !ex.user && !ex.customer && (
                                    <p className="text-sm text-gray-400 italic">No request context available (queue/console exception)</p>
                                )}
                            </dl>
                        </div>
                    </div>

                    {/* Context JSON */}
                    {ex.context && Object.keys(ex.context).some(k => ex.context[k]) && (
                        <div className="bg-white rounded-lg shadow p-4 mb-6">
                            <h3 className="text-sm font-medium text-gray-500 uppercase mb-3">Additional Context</h3>
                            <pre className="text-xs font-mono text-gray-700 bg-gray-50 rounded p-3 overflow-x-auto max-h-48">
                                {JSON.stringify(ex.context, null, 2)}
                            </pre>
                        </div>
                    )}

                    {/* Stack Trace */}
                    {ex.trace && (
                        <div className="bg-white rounded-lg shadow p-4">
                            <h3 className="text-sm font-medium text-gray-500 uppercase mb-3">Stack Trace</h3>
                            <pre className="text-xs font-mono text-gray-700 bg-gray-900 text-green-400 rounded p-4 overflow-x-auto max-h-[600px] overflow-y-auto whitespace-pre-wrap break-words">
                                {ex.trace}
                            </pre>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
