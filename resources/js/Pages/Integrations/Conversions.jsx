import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { money, count } from '@/utils/format';
import { useCurrency } from '@/hooks/useCurrency';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { usePolling } from '@/hooks/usePolling';
import { useToast } from '@/Components/Toast';

export default function Conversions({ conversions = [] }) {
    const [records, setRecords] = useState(conversions);
    const [pendingCount, setPendingCount] = useState(conversions.filter(record => record.upload_status === 'pending').length);
    const [retrying, setRetrying] = useState(false);
    const [timedOut, setTimedOut] = useState(false);
    const [watchAttempt, setWatchAttempt] = useState(0);
    const toast = useToast();
    useEffect(() => { setRecords(conversions); setPendingCount(conversions.filter(record => record.upload_status === 'pending').length); }, [conversions]);
    useEffect(() => { if (!pendingCount) return; const timer = setTimeout(() => setTimedOut(true), 10 * 60 * 1000); return () => clearTimeout(timer); }, [Boolean(pendingCount), watchAttempt]);
    const { data: progress, failureStreak } = usePolling(route('integrations.conversions.status'), { enabled: pendingCount > 0 && !timedOut, restartKey: watchAttempt, interval: 5000, parse: result => { if (!Array.isArray(result?.conversions)) throw new Error('Invalid conversion status'); return result; }, until: result => result.pending_count === 0 });
    useEffect(() => { if (progress) { setRecords(progress.conversions); setPendingCount(progress.pending_count); } }, [progress]);
    const checkAgain = () => { setTimedOut(false); setWatchAttempt(attempt => attempt + 1); router.reload(); };
    const retry = () => { setRetrying(true); router.post(route('integrations.retry-upload'), {}, { preserveScroll: true, onError: () => toast.error('We could not retry uploads. Try again.'), onFinish: () => setRetrying(false) }); };
    const currency = useCurrency();
    const statusColors = {
        pending: 'bg-yellow-100 text-yellow-700',
        uploaded_google: 'bg-blue-100 text-blue-700',
        uploaded_facebook: 'bg-indigo-100 text-indigo-700',
        uploaded_all: 'bg-green-100 text-green-700',
        failed: 'bg-red-100 text-red-700',
    };

    const hasFailed = records.some(c => c.upload_status === 'failed');

    return (
        <AuthenticatedLayout>
            <Head title="Offline Conversions" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl">
                    <div className="flex flex-wrap items-start justify-between gap-4 mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Offline Conversions</h1>
                            <p className="mt-1 text-sm text-gray-500">CRM conversions synced to ad platforms for closed-loop attribution.</p>
                        </div>
                        <div className="flex gap-2">
                            {hasFailed && (
                                <button disabled={retrying} onClick={retry} className="px-4 py-2 text-sm text-red-600 border border-red-200 rounded-lg hover:bg-red-50">{retrying ? 'Queuing…' : 'Retry failed uploads'}</button>
                            )}
                            <Link href={route('integrations.index')} className="text-sm text-gray-500 hover:text-gray-700">← Integrations</Link>
                        </div>
                    </div>

                    {pendingCount > 0 && <p role="status" className="mb-4 rounded-lg bg-blue-50 p-4 text-sm text-blue-800">{pendingCount} conversions are waiting for platform uploads. Progress updates here when an upload is confirmed.</p>}
                    {(timedOut || failureStreak >= 4) && <p role="alert" className="mb-4 text-sm text-amber-800">Latest upload progress is unavailable. Your conversion history is saved. <button onClick={checkAgain} className="font-semibold underline">Check again</button></p>}
                    <p className="mb-4 text-xs text-gray-500">Showing the most recent 100 conversions. Values use your business currency.</p>
                    {records.length > 0 ? (
                        <div className="bg-white rounded-lg border border-gray-200 overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Conversion</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Click ID</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {records.map(c => (
                                        <tr key={c.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-3 text-sm text-gray-600">{new Date(c.conversion_time).toLocaleDateString()}</td>
                                            <td className="px-4 py-3 text-sm font-medium text-gray-900">{c.conversion_name}</td>
                                            <td className="px-4 py-3 text-right text-sm text-gray-900">{c.conversion_value !== null ? money(c.conversion_value, currency) : '—'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex gap-1">
                                                    {c.gclid && <span className="text-xs px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded">G</span>}
                                                    {c.fbclid && <span className="text-xs px-1.5 py-0.5 bg-indigo-50 text-indigo-600 rounded">FB</span>}
                                                    {c.msclid && <span className="text-xs px-1.5 py-0.5 bg-teal-50 text-teal-600 rounded">MS</span>}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`text-xs px-2 py-0.5 rounded ${statusColors[c.upload_status] || 'bg-gray-100 text-gray-500'}`}>{c.upload_status.replace(/_/g, ' ')}</span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <h3 className="text-sm font-medium text-gray-900">No offline conversions yet</h3>
                            <p className="mt-1 text-sm text-gray-500">Connect a CRM to start syncing closed deals as offline conversions.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
