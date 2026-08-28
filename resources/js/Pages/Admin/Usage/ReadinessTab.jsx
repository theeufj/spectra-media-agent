import { Link } from '@inertiajs/react';
import { STATUS, INK } from '@/Components/Charts/palette';

/**
 * What is stopping each account from running ads.
 *
 * The engagement funnel says how many accounts reached each step; this names
 * the ones that did not. Blockers are graded because they need different people
 * to act: "waiting on Google" is not the same problem as "nobody has started".
 *
 * Colour never carries the grade on its own — every badge has its word next to
 * it, and the table is the primary artefact rather than a chart.
 */

const SEVERITY = {
    blocked: { label: 'Blocked', color: STATUS.critical, hint: 'Cannot advertise until someone acts' },
    stalled: { label: 'Stalled', color: STATUS.serious, hint: 'Work started and did not finish' },
    setup: { label: 'Setup', color: STATUS.warning, hint: 'Will run, but under-instrumented' },
    ready: { label: 'Ready', color: STATUS.good, hint: 'Nothing outstanding' },
};

const Badge = ({ severity }) => {
    const s = SEVERITY[severity] ?? SEVERITY.setup;

    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-medium text-gray-700">
            <span className="inline-block h-2 w-2 rounded-full" style={{ backgroundColor: s.color }} />
            {s.label}
        </span>
    );
};

function Readiness({ readiness }) {
    const { accounts, summary, blocker_counts: blockers } = readiness;

    const tiles = [
        { label: 'Accounts', value: summary.total, sub: 'excluding sandbox' },
        { label: 'Serving ads', value: summary.serving, sub: 'at least one live campaign' },
        { label: 'Blocked', value: summary.blocked, sub: 'cannot advertise', color: STATUS.critical },
        { label: 'Stalled', value: summary.stalled, sub: 'started, unfinished', color: STATUS.serious },
        { label: 'Never launched', value: summary.never_launched, sub: 'no campaign ever created' },
    ];

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                {tiles.map((t) => (
                    <div key={t.label} className="rounded-lg bg-white p-6 shadow">
                        <p className="truncate text-sm font-medium text-gray-500">{t.label}</p>
                        <p className="mt-1 text-2xl font-bold tabular-nums" style={{ color: t.color ?? INK.light.primary }}>
                            {t.value.toLocaleString()}
                        </p>
                        <p className="mt-1 text-xs text-gray-400">{t.sub}</p>
                    </div>
                ))}
            </div>

            {/* The "fix this once, unblock N accounts" view. */}
            <div className="rounded-lg bg-white shadow">
                <div className="border-b border-gray-200 px-6 py-4">
                    <h3 className="text-base font-semibold text-gray-900">What is blocking the most accounts</h3>
                    <p className="mt-1 text-xs text-gray-500">
                        One cause can hold up many accounts — this is the order worth working in.
                    </p>
                </div>
                <div className="p-6">
                    {blockers.length === 0 ? (
                        <p className="text-sm text-gray-500">Nothing outstanding across any account.</p>
                    ) : (
                        <div className="space-y-2">
                            {blockers.map((b) => (
                                <div key={b.key} className="flex items-center gap-3">
                                    <div className="w-28 flex-shrink-0"><Badge severity={b.severity} /></div>
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span className="truncate text-sm text-gray-700">{b.label}</span>
                                            <span className="text-sm font-semibold tabular-nums text-gray-900">
                                                {b.accounts}
                                            </span>
                                        </div>
                                        <div className="mt-1 h-1.5 w-full rounded bg-gray-100">
                                            <div
                                                className="h-1.5 rounded"
                                                style={{
                                                    width: `${(b.accounts / summary.total) * 100}%`,
                                                    backgroundColor: SEVERITY[b.severity]?.color ?? STATUS.warning,
                                                }}
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <div className="rounded-lg bg-white shadow">
                <div className="border-b border-gray-200 px-6 py-4">
                    <h3 className="text-base font-semibold text-gray-900">Every account</h3>
                    <p className="mt-1 text-xs text-gray-500">
                        Worst blocker first. {SEVERITY.blocked.hint.toLowerCase()}; {SEVERITY.stalled.hint.toLowerCase()}.
                    </p>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full">
                        <thead className="bg-gray-50">
                            <tr>
                                {['Account', 'State', 'Age', 'Campaigns', 'Live', 'Outstanding'].map((h, i) => (
                                    <th
                                        key={h}
                                        className={`px-4 py-2 text-xs font-semibold uppercase tracking-wider text-gray-500 ${
                                            i >= 2 && i <= 4 ? 'text-right' : 'text-left'
                                        }`}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {accounts.map((a) => (
                                <tr key={a.id} className="align-top hover:bg-gray-50">
                                    <td className="px-4 py-3 text-sm">
                                        <Link
                                            href={route('admin.customers.show', a.id)}
                                            className="font-medium text-brand-dark hover:underline"
                                        >
                                            {a.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3"><Badge severity={a.severity} /></td>
                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-gray-500">{a.age_days}d</td>
                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-gray-700">{a.campaigns}</td>
                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-gray-700">{a.live}</td>
                                    <td className="px-4 py-3 text-sm">
                                        {a.blockers.length === 0 ? (
                                            <span className="text-gray-400">—</span>
                                        ) : (
                                            <ul className="space-y-1">
                                                {a.blockers.map((b) => (
                                                    <li key={b.key} className="text-gray-700">
                                                        <span
                                                            className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full align-middle"
                                                            style={{ backgroundColor: SEVERITY[b.severity]?.color }}
                                                        />
                                                        {b.label}
                                                        {b.detail && <span className="text-gray-400"> — {b.detail}</span>}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

export default function ReadinessTab({ readiness }) {
    // The prop arrives with the page now, so there is nothing to wait for. The
    // guard is for the one case that would otherwise render a blank tab: a
    // response that somehow omitted it.
    if (!readiness?.summary) {
        return (
            <div className="rounded-lg bg-white p-6 shadow">
                <p className="text-sm text-gray-500">
                    Readiness data did not load. Reload the page; if it persists the report itself is failing.
                </p>
            </div>
        );
    }

    return <Readiness readiness={readiness} />;
}
