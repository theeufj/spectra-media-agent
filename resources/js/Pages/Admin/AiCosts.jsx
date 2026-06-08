import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import SideNav from './SideNav';

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmt = {
    usd:    (v) => `$${Number(v).toFixed(4)}`,
    usdBig: (v) => `$${Number(v).toFixed(2)}`,
    num:    (v) => Number(v).toLocaleString(),
    ms:     (v) => v >= 1000 ? `${(v / 1000).toFixed(1)}s` : `${Math.round(v)}ms`,
    pct:    (v) => `${v}%`,
    tokens: (v) => v >= 1_000_000 ? `${(v / 1_000_000).toFixed(2)}M` : v >= 1000 ? `${(v / 1000).toFixed(1)}K` : v,
};

// ── Shared components ─────────────────────────────────────────────────────────

const Card = ({ title, value, sub, icon, color = 'flame', trend }) => {
    const bg = {
        flame: 'bg-flame-orange-500', green: 'bg-green-500',
        blue: 'bg-blue-500', purple: 'bg-purple-500', red: 'bg-red-500',
    };
    return (
        <div className="bg-white rounded-lg shadow p-6">
            <div className="flex items-center">
                <div className={`flex-shrink-0 p-3 rounded-lg ${bg[color]}`}>
                    <span className="text-white text-xl">{icon}</span>
                </div>
                <div className="ml-4 flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-500 truncate">{title}</p>
                    <p className="text-2xl font-bold text-gray-900">{value}</p>
                    {sub && <p className="text-xs text-gray-400">{sub}</p>}
                </div>
                {trend != null && (
                    <div className={`text-sm font-semibold ml-2 ${trend >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                        {trend >= 0 ? '↑' : '↓'} {Math.abs(trend)}%
                    </div>
                )}
            </div>
        </div>
    );
};

const Section = ({ title, children }) => (
    <div className="bg-white rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200">
            <h3 className="text-base font-semibold text-gray-900">{title}</h3>
        </div>
        <div className="p-6">{children}</div>
    </div>
);

const Th = ({ children, right }) => (
    <th className={`px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider ${right ? 'text-right' : 'text-left'}`}>
        {children}
    </th>
);
const Td = ({ children, right, mono, bold }) => (
    <td className={`px-4 py-3 text-sm whitespace-nowrap ${right ? 'text-right' : ''} ${mono ? 'font-mono' : ''} ${bold ? 'font-semibold text-gray-900' : 'text-gray-700'}`}>
        {children}
    </td>
);

const PctBar = ({ pct, color = 'bg-flame-orange-400' }) => (
    <div className="flex items-center gap-2">
        <div className="flex-1 bg-gray-100 rounded-full h-1.5">
            <div className={`${color} h-1.5 rounded-full`} style={{ width: `${Math.min(pct, 100)}%` }} />
        </div>
        <span className="text-xs text-gray-500 w-10 text-right">{pct}%</span>
    </div>
);

const ModelBadge = ({ model }) => {
    const color = model.includes('pro')   ? 'bg-purple-100 text-purple-800'
                : model.includes('lite')  ? 'bg-green-100 text-green-800'
                : model.includes('veo')   ? 'bg-blue-100 text-blue-800'
                : model.includes('embed') ? 'bg-gray-100 text-gray-700'
                : 'bg-orange-100 text-orange-800';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${color}`}>
            {model}
        </span>
    );
};

// ── Daily bar chart ───────────────────────────────────────────────────────────

const DailyChart = ({ data }) => {
    const max = Math.max(...data.map(d => d.total_cost), 0.0001);
    return (
        <div>
            <div className="flex items-end justify-between h-40 gap-1">
                {data.map((d, i) => (
                    <div key={i} className="flex flex-col items-center flex-1 group relative">
                        <div
                            className="w-full bg-flame-orange-400 hover:bg-flame-orange-500 rounded-t transition-all cursor-default"
                            style={{ height: `${(d.total_cost / max) * 100}%`, minHeight: d.total_cost > 0 ? '3px' : '0' }}
                        />
                        <div className="absolute bottom-full mb-1 hidden group-hover:block z-10 bg-gray-900 text-white text-xs rounded px-2 py-1 whitespace-nowrap">
                            {d.date}<br />{fmt.usd(d.total_cost)} · {fmt.num(d.calls)} calls
                        </div>
                    </div>
                ))}
            </div>
            <div className="flex justify-between mt-2">
                <span className="text-xs text-gray-400">{data[0]?.date ?? ''}</span>
                <span className="text-xs text-gray-400">{data[data.length - 1]?.date ?? ''}</span>
            </div>
        </div>
    );
};

// ── Main page ─────────────────────────────────────────────────────────────────

export default function AiCosts({ auth, summary, byModel, byOperation, byCustomer, unattributed, daily, fallbacks, period }) {
    const [activePeriod, setActivePeriod] = useState(period);

    const changePeriod = (p) => {
        setActivePeriod(p);
        router.get(route('admin.ai-costs.index'), { period: p }, { preserveState: true, preserveScroll: true });
    };

    const periods = [
        { label: '7d', value: '7' },
        { label: '30d', value: '30' },
        { label: '90d', value: '90' },
    ];

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="AI Costs — Admin" />
            <div className="flex min-h-screen bg-gray-50">
                <SideNav />
                <div className="flex-1 p-8 overflow-auto">

                    {/* Header */}
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">AI Cost Dashboard</h1>
                            <p className="text-sm text-gray-500 mt-1">Gemini API spend across all customers and agents</p>
                        </div>
                        <div className="flex gap-2">
                            {periods.map(p => (
                                <button
                                    key={p.value}
                                    onClick={() => changePeriod(p.value)}
                                    className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${
                                        activePeriod === p.value
                                            ? 'bg-flame-orange-500 text-white'
                                            : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'
                                    }`}
                                >
                                    {p.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Summary cards */}
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <Card
                            title="Total AI Spend"
                            value={fmt.usdBig(summary.total_cost)}
                            sub={`vs prev period`}
                            icon="💰"
                            color="flame"
                            trend={summary.cost_trend}
                        />
                        <Card
                            title="Total API Calls"
                            value={fmt.num(summary.total_calls)}
                            sub={`avg ${fmt.usd(summary.avg_cost_per_call)} / call`}
                            icon="⚡"
                            color="blue"
                        />
                        <Card
                            title="Tokens Processed"
                            value={fmt.tokens(summary.total_input_tokens + summary.total_output_tokens)}
                            sub={`${fmt.tokens(summary.total_cached_tokens)} cached`}
                            icon="🔤"
                            color="purple"
                        />
                        <Card
                            title="Avg Latency"
                            value={fmt.ms(summary.avg_duration_ms)}
                            sub="per API call"
                            icon="⏱"
                            color="green"
                        />
                    </div>

                    {/* Daily chart */}
                    {daily.length > 0 && (
                        <div className="mb-6">
                            <Section title="Daily Spend">
                                <DailyChart data={daily} />
                            </Section>
                        </div>
                    )}

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

                        {/* By model */}
                        <Section title="Cost by Model">
                            {byModel.length === 0 ? (
                                <p className="text-sm text-gray-400 text-center py-8">No data yet for this period.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full">
                                        <thead className="border-b border-gray-100">
                                            <tr>
                                                <Th>Model</Th>
                                                <Th right>Cost</Th>
                                                <Th right>Calls</Th>
                                                <Th right>Tokens in</Th>
                                                <Th right>Tokens out</Th>
                                                <Th right>Cached</Th>
                                                <Th right>Avg ms</Th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {byModel.map((r) => (
                                                <tr key={r.model} className="hover:bg-gray-50">
                                                    <td className="px-4 py-3">
                                                        <ModelBadge model={r.model} />
                                                        <div className="mt-1">
                                                            <PctBar pct={r.pct} />
                                                        </div>
                                                    </td>
                                                    <Td right bold mono>{fmt.usd(r.total_cost)}</Td>
                                                    <Td right>{fmt.num(r.calls)}</Td>
                                                    <Td right>{fmt.tokens(r.input_tokens)}</Td>
                                                    <Td right>{fmt.tokens(r.output_tokens)}</Td>
                                                    <Td right>
                                                        {r.cached_tokens > 0
                                                            ? <span className="text-green-600">{fmt.tokens(r.cached_tokens)}</span>
                                                            : <span className="text-gray-300">—</span>}
                                                    </Td>
                                                    <Td right>{fmt.ms(r.avg_duration_ms)}</Td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Section>

                        {/* By operation */}
                        <Section title="Cost by Operation">
                            {byOperation.length === 0 ? (
                                <p className="text-sm text-gray-400 text-center py-8">No data yet for this period.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full">
                                        <thead className="border-b border-gray-100">
                                            <tr>
                                                <Th>Operation</Th>
                                                <Th>Task type</Th>
                                                <Th right>Cost</Th>
                                                <Th right>Calls</Th>
                                                <Th right>Avg ms</Th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {byOperation.map((r, i) => (
                                                <tr key={i} className="hover:bg-gray-50">
                                                    <td className="px-4 py-3">
                                                        <p className="text-sm font-medium text-gray-900 truncate max-w-[180px]" title={r.operation}>
                                                            {r.operation}
                                                        </p>
                                                        <div className="mt-1">
                                                            <PctBar pct={r.pct} color="bg-blue-400" />
                                                        </div>
                                                    </td>
                                                    <Td>
                                                        {r.task_type !== '—'
                                                            ? <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">{r.task_type}</span>
                                                            : <span className="text-gray-300">—</span>}
                                                    </Td>
                                                    <Td right bold mono>{fmt.usd(r.total_cost)}</Td>
                                                    <Td right>{fmt.num(r.calls)}</Td>
                                                    <Td right>{fmt.ms(r.avg_duration_ms)}</Td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Section>
                    </div>

                    {/* Per customer */}
                    <div className="mb-6">
                        <Section title="Cost per Customer">
                            {byCustomer.length === 0 && unattributed.calls === 0 ? (
                                <p className="text-sm text-gray-400 text-center py-8">No attributed cost data yet.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full">
                                        <thead className="border-b border-gray-100">
                                            <tr>
                                                <Th>Customer</Th>
                                                <Th right>Total Cost</Th>
                                                <Th right>Share</Th>
                                                <Th right>Calls</Th>
                                                <Th right>Tokens in</Th>
                                                <Th right>Tokens out</Th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {byCustomer.map((r) => (
                                                <tr key={r.customer_id} className="hover:bg-gray-50">
                                                    <td className="px-4 py-3">
                                                        <p className="text-sm font-semibold text-gray-900">{r.customer_name}</p>
                                                        <p className="text-xs text-gray-400">ID #{r.customer_id}</p>
                                                    </td>
                                                    <Td right bold mono>{fmt.usd(r.total_cost)}</Td>
                                                    <Td right>
                                                        <PctBar pct={r.pct} color="bg-purple-400" />
                                                    </Td>
                                                    <Td right>{fmt.num(r.calls)}</Td>
                                                    <Td right>{fmt.tokens(r.input_tokens)}</Td>
                                                    <Td right>{fmt.tokens(r.output_tokens)}</Td>
                                                </tr>
                                            ))}
                                            {unattributed.calls > 0 && (
                                                <tr className="bg-gray-50">
                                                    <td className="px-4 py-3">
                                                        <p className="text-sm text-gray-500 italic">Unattributed (platform / system calls)</p>
                                                    </td>
                                                    <Td right mono>{fmt.usd(unattributed.cost)}</Td>
                                                    <td className="px-4 py-3" />
                                                    <Td right>{fmt.num(unattributed.calls)}</Td>
                                                    <Td right>—</Td>
                                                    <Td right>—</Td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Section>
                    </div>

                    {/* Fallback events */}
                    {fallbacks.length > 0 && (
                        <div className="mb-6">
                            <Section title="Model Fallback Events">
                                <p className="text-sm text-gray-500 mb-4">Calls where the primary model failed and automatically fell back to a cheaper model.</p>
                                <div className="space-y-3">
                                    {fallbacks.map((f, i) => (
                                        <div key={i} className="flex items-center justify-between p-3 bg-amber-50 border border-amber-100 rounded-lg">
                                            <div className="flex items-center gap-2">
                                                <span className="text-amber-500">⚠️</span>
                                                <span className="text-sm font-mono text-gray-800">{f.chain}</span>
                                            </div>
                                            <div className="flex items-center gap-4 text-sm text-gray-600">
                                                <span>{fmt.num(f.count)} events</span>
                                                <span className="font-mono text-gray-900">{fmt.usd(f.cost)}</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </Section>
                        </div>
                    )}

                    {/* Token efficiency callout */}
                    {summary.total_cached_tokens > 0 && (
                        <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg flex items-start gap-3">
                            <span className="text-green-500 text-xl">💚</span>
                            <div>
                                <p className="text-sm font-semibold text-green-800">Prompt caching active</p>
                                <p className="text-sm text-green-700">
                                    {fmt.tokens(summary.total_cached_tokens)} cached tokens saved approximately{' '}
                                    <strong>{fmt.usdBig(summary.total_cached_tokens * 0.056 / 1_000_000)}</strong> vs full price.
                                </p>
                            </div>
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
