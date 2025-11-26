import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import SideNav from './SideNav';

const StatusBadge = ({ status }) => {
    const colors = {
        healthy: 'bg-green-100 text-green-800',
        configured: 'bg-blue-100 text-blue-800',
        warning: 'bg-yellow-100 text-yellow-800',
        error: 'bg-red-100 text-red-800',
        not_configured: 'bg-gray-100 text-gray-500',
    };

    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colors[status] || colors.error}`}>
            {status === 'healthy' && <span className="w-2 h-2 mr-1.5 bg-green-500 rounded-full animate-pulse"></span>}
            {status === 'error' && <span className="w-2 h-2 mr-1.5 bg-red-500 rounded-full"></span>}
            {status === 'warning' && <span className="w-2 h-2 mr-1.5 bg-yellow-500 rounded-full"></span>}
            {status?.replace('_', ' ').charAt(0).toUpperCase() + status?.replace('_', ' ').slice(1)}
        </span>
    );
};

const ApiCard = ({ api }) => {
    const icons = {
        google: (
            <svg className="w-8 h-8" viewBox="0 0 24 24" fill="currentColor">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
        ),
        facebook: (
            <svg className="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
        ),
        sparkles: (
            <svg className="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
            </svg>
        ),
        'credit-card': (
            <svg className="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
            </svg>
        ),
        cloud: (
            <svg className="w-8 h-8 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
            </svg>
        ),
    };

    return (
        <div className="bg-white rounded-lg shadow p-6">
            <div className="flex items-center justify-between">
                <div className="flex items-center">
                    <div className="flex-shrink-0">
                        {icons[api.icon] || icons.cloud}
                    </div>
                    <div className="ml-4">
                        <h3 className="text-lg font-medium text-gray-900">{api.name}</h3>
                        <p className="text-sm text-gray-500">{api.message}</p>
                    </div>
                </div>
                <StatusBadge status={api.status} />
            </div>
        </div>
    );
};

export default function SystemHealth({ health }) {
    const [healthData, setHealthData] = React.useState(health);
    const [refreshing, setRefreshing] = React.useState(false);

    const refreshHealth = async () => {
        setRefreshing(true);
        try {
            const response = await fetch(route('admin.health.check'));
            const data = await response.json();
            setHealthData(data);
        } catch (error) {
            console.error('Failed to refresh health data:', error);
        }
        setRefreshing(false);
    };

    const handleRetryJob = (jobId) => {
        router.post(route('admin.health.retry-job', jobId), {}, { preserveScroll: true });
    };

    const handleDeleteJob = (jobId) => {
        if (confirm('Are you sure you want to delete this failed job?')) {
            router.delete(route('admin.health.delete-job', jobId), { preserveScroll: true });
        }
    };

    const handleFlushJobs = () => {
        if (confirm('Are you sure you want to delete ALL failed jobs?')) {
            router.post(route('admin.health.flush-jobs'), {}, { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">System Health</h2>}
        >
            <Head title="System Health" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-6xl mx-auto">
                        {/* Header with refresh */}
                        <div className="flex justify-between items-center mb-6">
                            <div>
                                <h1 className="text-2xl font-bold text-gray-900">System Health Dashboard</h1>
                                <p className="text-sm text-gray-500">
                                    Last checked: {new Date(healthData.checkedAt).toLocaleString()}
                                </p>
                            </div>
                            <button
                                onClick={refreshHealth}
                                disabled={refreshing}
                                className="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                            >
                                <svg className={`w-4 h-4 mr-2 ${refreshing ? 'animate-spin' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                {refreshing ? 'Refreshing...' : 'Refresh'}
                            </button>
                        </div>

                        {/* API Status Grid */}
                        <div className="mb-8">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">External Services</h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                {Object.values(healthData.apis).map((api, index) => (
                                    <ApiCard key={index} api={api} />
                                ))}
                            </div>
                        </div>

                        {/* Database & Infrastructure */}
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                            {/* Database */}
                            <div className="bg-white rounded-lg shadow p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-lg font-medium text-gray-900">Database</h3>
                                    <StatusBadge status={healthData.database.status} />
                                </div>
                                <p className="text-sm text-gray-500 mb-4">{healthData.database.message}</p>
                                {healthData.database.stats && (
                                    <div className="space-y-2 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-gray-500">Users</span>
                                            <span className="font-medium">{healthData.database.stats.users}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-500">Campaigns</span>
                                            <span className="font-medium">{healthData.database.stats.campaigns}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-500">Customers</span>
                                            <span className="font-medium">{healthData.database.stats.customers}</span>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Cache */}
                            <div className="bg-white rounded-lg shadow p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-lg font-medium text-gray-900">Cache</h3>
                                    <StatusBadge status={healthData.cache.status} />
                                </div>
                                <p className="text-sm text-gray-500 mb-2">{healthData.cache.message}</p>
                                <p className="text-sm text-gray-400">Driver: {healthData.cache.driver}</p>
                            </div>

                            {/* Storage */}
                            <div className="bg-white rounded-lg shadow p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-lg font-medium text-gray-900">Storage</h3>
                                    <StatusBadge status={healthData.storage.status} />
                                </div>
                                {healthData.storage.usedPercent !== undefined && (
                                    <>
                                        <div className="w-full bg-gray-200 rounded-full h-2.5 mb-2">
                                            <div 
                                                className={`h-2.5 rounded-full ${healthData.storage.usedPercent > 90 ? 'bg-red-600' : 'bg-indigo-600'}`}
                                                style={{ width: `${healthData.storage.usedPercent}%` }}
                                            ></div>
                                        </div>
                                        <p className="text-sm text-gray-500">
                                            {healthData.storage.used} / {healthData.storage.total} ({healthData.storage.usedPercent}%)
                                        </p>
                                    </>
                                )}
                            </div>
                        </div>

                        {/* Queue Status */}
                        <div className="bg-white rounded-lg shadow p-6 mb-8">
                            <div className="flex items-center justify-between mb-4">
                                <div>
                                    <h3 className="text-lg font-medium text-gray-900">Queue Status</h3>
                                    <p className="text-sm text-gray-500">Driver: {healthData.queue.driver}</p>
                                </div>
                                <div className="flex items-center space-x-4">
                                    <div className="text-center">
                                        <div className="text-2xl font-bold text-gray-900">{healthData.queue.pending}</div>
                                        <div className="text-xs text-gray-500">Pending</div>
                                    </div>
                                    <div className="text-center">
                                        <div className={`text-2xl font-bold ${healthData.queue.failed > 0 ? 'text-red-600' : 'text-gray-900'}`}>
                                            {healthData.queue.failed}
                                        </div>
                                        <div className="text-xs text-gray-500">Failed</div>
                                    </div>
                                    {healthData.queue.failed > 0 && (
                                        <button
                                            onClick={handleFlushJobs}
                                            className="text-sm text-red-600 hover:text-red-800"
                                        >
                                            Clear All
                                        </button>
                                    )}
                                </div>
                            </div>

                            {/* Recent Failed Jobs */}
                            {healthData.queue.recentFailed && healthData.queue.recentFailed.length > 0 && (
                                <div>
                                    <h4 className="text-sm font-medium text-gray-700 mb-2">Recent Failed Jobs</h4>
                                    <div className="space-y-2">
                                        {healthData.queue.recentFailed.map((job) => (
                                            <div key={job.id} className="bg-red-50 rounded-lg p-3">
                                                <div className="flex justify-between items-start">
                                                    <div>
                                                        <p className="font-medium text-red-800">{job.job}</p>
                                                        <p className="text-xs text-red-600">Queue: {job.queue} • {job.failed_at}</p>
                                                        <p className="text-xs text-gray-600 mt-1 line-clamp-2">{job.exception}</p>
                                                    </div>
                                                    <div className="flex space-x-2">
                                                        <button
                                                            onClick={() => handleRetryJob(job.id)}
                                                            className="text-xs text-indigo-600 hover:text-indigo-800"
                                                        >
                                                            Retry
                                                        </button>
                                                        <button
                                                            onClick={() => handleDeleteJob(job.id)}
                                                            className="text-xs text-red-600 hover:text-red-800"
                                                        >
                                                            Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
