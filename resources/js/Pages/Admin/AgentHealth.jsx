import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import SideNav from './SideNav';

const STATUS = {
    completed:  { label: 'Ran · made changes', cls: 'bg-green-100 text-green-700', dot: 'bg-green-500' },
    no_op:      { label: 'Ran · no changes',   cls: 'bg-amber-100 text-amber-700', dot: 'bg-amber-400' },
    partial:    { label: 'Ran · some errors',  cls: 'bg-orange-100 text-orange-700', dot: 'bg-orange-500' },
    failed:     { label: 'Failed',             cls: 'bg-red-100 text-red-700', dot: 'bg-red-500' },
    never_run:  { label: 'Never ran',          cls: 'bg-red-100 text-red-700', dot: 'bg-red-500' },
};

const badge = (s) => STATUS[s] || { label: s, cls: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400' };

const ago = (h) => {
    if (h == null) return 'never';
    if (h < 1) return `${Math.round(h * 60)}m ago`;
    if (h < 48) return `${Math.round(h)}h ago`;
    return `${Math.round(h / 24)}d ago`;
};

const Stat = ({ label, value, color }) => (
    <div className="bg-white rounded-lg shadow p-5">
        <p className="text-sm font-medium text-gray-500">{label}</p>
        <p className={`text-3xl font-bold ${color}`}>{value}</p>
    </div>
);

export default function AgentHealth({ auth, jobs = [] }) {
    const stale   = jobs.filter(j => j.is_stale).length;
    const failing = jobs.filter(j => j.last_status === 'failed' || j.last_status === 'never_run').length;
    const idle    = jobs.filter(j => j.no_op_streak >= 3 && !j.is_stale).length;
    const healthy = jobs.filter(j => !j.is_stale && j.last_status !== 'failed' && j.last_status !== 'never_run').length;

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Automation Health — Admin" />
            <div className="flex min-h-screen bg-gray-50">
                <SideNav />
                <main className="flex-1 p-6 lg:p-8 max-w-6xl">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Automation Health</h1>
                            <p className="text-sm text-gray-500 mt-1">
                                Every scheduled optimization job leaves a run trace. Stale or failing jobs surface here instead of failing silently.
                            </p>
                        </div>
                        <button
                            onClick={() => router.reload({ only: ['jobs'] })}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                        >
                            ↻ Refresh
                        </button>
                    </div>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <Stat label="Healthy" value={healthy} color="text-green-600" />
                        <Stat label="Stale (not running)" value={stale} color={stale ? 'text-red-600' : 'text-gray-900'} />
                        <Stat label="Failing" value={failing} color={failing ? 'text-red-600' : 'text-gray-900'} />
                        <Stat label="Idle (3+ no-op runs)" value={idle} color={idle ? 'text-amber-600' : 'text-gray-900'} />
                    </div>

                    <div className="bg-white rounded-lg shadow overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        {['Job', 'Last run', 'Status', 'Changes', 'Errors', 'Scope', 'Recent'].map((h, i) => (
                                            <th key={h} className={`px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider ${i >= 3 && i <= 4 ? 'text-right' : 'text-left'}`}>{h}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {jobs.length === 0 && (
                                        <tr><td colSpan={7} className="px-4 py-8 text-center text-sm text-gray-400">No runs recorded yet — jobs will appear here after their next scheduled run.</td></tr>
                                    )}
                                    {jobs.map((j) => {
                                        const b = badge(j.last_status);
                                        return (
                                            <tr key={j.job} className={j.is_stale ? 'bg-red-50' : ''}>
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-gray-900 text-sm">{j.job}</div>
                                                    {j.note && <div className="text-xs text-gray-400 truncate max-w-xs">{j.note}</div>}
                                                </td>
                                                <td className="px-4 py-3 text-sm">
                                                    <span className={j.is_stale ? 'text-red-600 font-medium' : 'text-gray-600'}>{ago(j.age_hours)}</span>
                                                    {j.is_stale && j.expected_gap && (
                                                        <div className="text-xs text-red-500">expected every ~{j.expected_gap}h</div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${b.cls}`}>
                                                        <span className={`w-1.5 h-1.5 rounded-full ${b.dot}`} />
                                                        {b.label}
                                                    </span>
                                                    {j.no_op_streak >= 3 && j.last_status === 'no_op' && (
                                                        <div className="text-xs text-amber-600 mt-1">{j.no_op_streak} runs with no changes</div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right text-sm font-medium text-gray-900">{j.actions}</td>
                                                <td className={`px-4 py-3 text-right text-sm font-medium ${j.errors > 0 ? 'text-red-600' : 'text-gray-400'}`}>{j.errors}</td>
                                                <td className="px-4 py-3 text-sm text-gray-500">{j.scope || '—'}</td>
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-1">
                                                        {(j.recent || []).slice().reverse().map((r, i) => (
                                                            <span key={i} title={`${r.status} · ${r.actions} changes · ${r.errors} errors`}
                                                                className={`w-2.5 h-2.5 rounded-sm ${badge(r.status).dot}`} />
                                                        ))}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </main>
            </div>
        </AuthenticatedLayout>
    );
}
