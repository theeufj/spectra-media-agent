import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import SideNav from './SideNav';
import { useState } from 'react';

export default function RuntimeExceptions({ auth, exceptions, stats, types, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [source, setSource] = useState(filters.source || '');
    const [type, setType] = useState(filters.type || '');

    const applyFilters = (overrides = {}) => {
        router.get(route('admin.runtime-exceptions.index'), {
            search: overrides.search ?? search,
            source: overrides.source ?? source,
            type: overrides.type ?? type,
        }, { preserveState: true, preserveScroll: true });
    };

    const handleSearch = (e) => {
        e.preventDefault();
        applyFilters();
    };

    const handleFlush = (days) => {
        if (confirm(`Delete all exceptions older than ${days} days?`)) {
            router.post(route('admin.runtime-exceptions.flush'), { days });
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

    const timeAgo = (date) => {
        const seconds = Math.floor((new Date() - new Date(date)) / 1000);
        if (seconds < 60) return `${seconds}s ago`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
        return `${Math.floor(seconds / 86400)}d ago`;
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Runtime Exceptions" />
            <div className="flex">
                <SideNav />
                <div className="flex-1 p-6">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Runtime Exceptions</h1>
                            <p className="mt-1 text-sm text-gray-500">All unhandled exceptions from HTTP requests, queue jobs, and console commands.</p>
                        </div>
                        <div className="flex gap-2">
                            <button onClick={() => handleFlush(30)} className="px-3 py-2 text-sm font-medium text-red-700 bg-red-50 rounded-md hover:bg-red-100">
                                Flush 30d+
                            </button>
                            <button onClick={() => handleFlush(7)} className="px-3 py-2 text-sm font-medium text-red-700 bg-red-50 rounded-md hover:bg-red-100">
                                Flush 7d+
                            </button>
                        </div>
                    </div>

                    {/* Stats Cards */}
                    <div className="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
                        <div className="bg-white rounded-lg shadow p-4">
                            <p className="text-xs font-medium text-gray-500 uppercase">Total</p>
                            <p className="text-2xl font-bold text-gray-900">{stats.total.toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow p-4">
                            <p className="text-xs font-medium text-gray-500 uppercase">Today</p>
                            <p className="text-2xl font-bold text-red-600">{stats.today}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow p-4">
                            <p className="text-xs font-medium text-gray-500 uppercase">This Week</p>
                            <p className="text-2xl font-bold text-orange-600">{stats.this_week}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow p-4">
                            <p className="text-xs font-medium text-blue-500 uppercase">HTTP Today</p>
                            <p className="text-2xl font-bold text-blue-600">{stats.http}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow p-4">
                            <p className="text-xs font-medium text-purple-500 uppercase">Queue Today</p>
                            <p className="text-2xl font-bold text-purple-600">{stats.queue}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow p-4">
                            <p className="text-xs font-medium text-gray-500 uppercase">Console Today</p>
                            <p className="text-2xl font-bold text-gray-600">{stats.console}</p>
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="bg-white rounded-lg shadow p-4 mb-6">
                        <form onSubmit={handleSearch} className="flex flex-col sm:flex-row gap-3">
                            <div className="flex-1">
                                <input
                                    type="text"
                                    placeholder="Search message, type, file, job, URL..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                />
                            </div>
                            <select
                                value={source}
                                onChange={(e) => { setSource(e.target.value); applyFilters({ source: e.target.value }); }}
                                className="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            >
                                <option value="">All Sources</option>
                                <option value="http">HTTP</option>
                                <option value="queue">Queue</option>
                                <option value="console">Console</option>
                            </select>
                            <select
                                value={type}
                                onChange={(e) => { setType(e.target.value); applyFilters({ type: e.target.value }); }}
                                className="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm max-w-xs"
                            >
                                <option value="">All Types</option>
                                {types.map((t) => (
                                    <option key={t} value={t}>{shortType(t)}</option>
                                ))}
                            </select>
                            <button type="submit" className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                                Search
                            </button>
                            {(filters.search || filters.source || filters.type) && (
                                <button
                                    type="button"
                                    onClick={() => { setSearch(''); setSource(''); setType(''); router.get(route('admin.runtime-exceptions.index')); }}
                                    className="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-200"
                                >
                                    Clear
                                </button>
                            )}
                        </form>
                    </div>

                    {/* Exception List */}
                    <div className="bg-white rounded-lg shadow overflow-hidden">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">When</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"></th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {exceptions.data.length === 0 ? (
                                    <tr>
                                        <td colSpan="6" className="px-4 py-12 text-center text-gray-500">
                                            No exceptions found. Your app is running clean!
                                        </td>
                                    </tr>
                                ) : (
                                    exceptions.data.map((ex) => (
                                        <tr key={ex.id} className="hover:bg-gray-50 cursor-pointer" onClick={() => router.get(route('admin.runtime-exceptions.show', ex.id))}>
                                            <td className="px-4 py-3 text-sm text-gray-500 whitespace-nowrap" title={new Date(ex.created_at).toLocaleString()}>
                                                {timeAgo(ex.created_at)}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${sourceColor(ex.source)}`}>
                                                    {ex.source}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-900 whitespace-nowrap font-mono">
                                                {shortType(ex.type)}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-700 max-w-md truncate">
                                                {ex.message?.substring(0, 120)}{ex.message?.length > 120 ? '...' : ''}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap font-mono">
                                                {ex.file?.split('/').pop()}:{ex.line}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link href={route('admin.runtime-exceptions.show', ex.id)} className="text-indigo-600 hover:text-indigo-900 text-sm">
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>

                        {/* Pagination */}
                        {exceptions.last_page > 1 && (
                            <div className="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200">
                                <div className="text-sm text-gray-700">
                                    Showing {exceptions.from} to {exceptions.to} of {exceptions.total} exceptions
                                </div>
                                <div className="flex gap-1">
                                    {exceptions.links.map((link, i) => (
                                        <button
                                            key={i}
                                            disabled={!link.url}
                                            onClick={() => link.url && router.get(link.url)}
                                            className={`px-3 py-1 text-sm rounded ${
                                                link.active
                                                    ? 'bg-indigo-600 text-white'
                                                    : link.url
                                                    ? 'bg-white text-gray-700 hover:bg-gray-50 border'
                                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'
                                            }`}
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
