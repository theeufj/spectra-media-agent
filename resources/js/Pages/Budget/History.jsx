import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

function LogEntry({ log }) {
    const before = log.before_allocation || {};
    const after = log.after_allocation || {};
    const date = new Date(log.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    const platforms = ['google_ads_pct', 'facebook_ads_pct', 'microsoft_ads_pct'];
    const names = { google_ads_pct: 'Google', facebook_ads_pct: 'Facebook', microsoft_ads_pct: 'Microsoft' };
    const changes = platforms.map(p => ({
        name: names[p],
        before: parseFloat(before[p] || 0),
        after: parseFloat(after[p] || 0),
        diff: parseFloat(after[p] || 0) - parseFloat(before[p] || 0),
    })).filter(c => Math.abs(c.diff) >= 0.1);

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-3">
                    <span className="text-sm font-medium text-gray-900">{date}</span>
                    <span className={`text-xs px-2 py-0.5 rounded ${log.auto_executed ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'}`}>
                        {log.trigger}
                    </span>
                </div>
                {log.estimated_improvement_pct > 0 && (
                    <span className="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded">+{log.estimated_improvement_pct}% projected</span>
                )}
            </div>
            <div className="flex flex-wrap gap-3">
                {changes.map(c => (
                    <div key={c.name} className="text-sm">
                        <span className="text-gray-500">{c.name}:</span>{' '}
                        <span className="text-gray-400">{c.before.toFixed(1)}%</span>
                        <span className="text-gray-400 mx-1">→</span>
                        <span className="font-medium">{c.after.toFixed(1)}%</span>
                        <span className={`ml-1 text-xs ${c.diff > 0 ? 'text-green-600' : 'text-red-600'}`}>
                            ({c.diff > 0 ? '+' : ''}{c.diff.toFixed(1)})
                        </span>
                    </div>
                ))}
            </div>
            {log.recommendations?.reasons?.length > 0 && (
                <div className="mt-2 text-xs text-gray-500">
                    {log.recommendations.reasons.join(' · ')}
                </div>
            )}
        </div>
    );
}

export default function History({ logs = [] }) {
    return (
        <AuthenticatedLayout>
            <Head title="Rebalance History" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Rebalance History</h1>
                            <p className="mt-1 text-sm text-gray-500">Log of all cross-channel budget rebalancing events.</p>
                        </div>
                        <a href={route('budget.allocator')} className="text-sm text-gray-500 hover:text-gray-700">← Back to Allocator</a>
                    </div>

                    {logs.length > 0 ? (
                        <div className="space-y-3">
                            {logs.map(log => <LogEntry key={log.id} log={log} />)}
                        </div>
                    ) : (
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <h3 className="text-sm font-medium text-gray-900">No rebalance history</h3>
                            <p className="mt-1 text-sm text-gray-500">Budget rebalancing events will appear here.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
