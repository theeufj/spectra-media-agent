import { Deferred, Link } from '@inertiajs/react';
import { Bar } from 'react-chartjs-2';
import FunnelChart from './FunnelChart';
import { seriesColor, INK, STATUS } from '@/Components/Charts/palette';

const Section = ({ title, subtitle, action, children }) => (
    <div className="bg-white rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200 flex items-start justify-between gap-4">
            <div>
                <h3 className="text-base font-semibold text-gray-900">{title}</h3>
                {subtitle && <p className="text-xs text-gray-500 mt-1">{subtitle}</p>}
            </div>
            {action}
        </div>
        <div className="p-6">{children}</div>
    </div>
);

const Loading = ({ label }) => (
    <div className="bg-white rounded-lg shadow p-6">
        <p className="text-sm text-gray-400">Loading {label}…</p>
    </div>
);

/**
 * Campaigns where Spectra's `status` and the platform's `primary_status`
 * disagree.
 *
 * This is a billing correctness panel wearing an engagement panel's clothes:
 * the same disagreement caused BILL-8. The counts are lifetime, not
 * period-scoped, because a campaign that drifted eight months ago is still
 * wrong today.
 */
const StatusDrift = ({ drift }) => {
    const cells = [
        {
            label: 'Believed live, not serving',
            value: drift.believed_live_but_not,
            hint: 'We think these are running. The platform disagrees.',
            color: STATUS.critical,
        },
        {
            label: 'Believed stopped, still serving',
            value: drift.believed_stopped_but_live,
            hint: 'Spending money that our own status does not account for.',
            color: STATUS.serious,
        },
        {
            label: 'Never reconciled',
            value: drift.unchecked,
            hint: 'Marked active but never checked against the platform.',
            color: STATUS.warning,
        },
    ];

    const clean = cells.every((c) => c.value === 0);

    return (
        <Section
            title="Status drift"
            subtitle="Spectra's own campaign status against what the ad platform reports. These drift by design; large numbers here mean billing is working from the wrong picture."
            action={
                <Link href={route('admin.runtime-exceptions.index')} className="text-xs text-flame-orange-600 hover:underline whitespace-nowrap">
                    Exceptions →
                </Link>
            }
        >
            {clean ? (
                <p className="text-sm text-gray-500">No drift detected — every campaign's status matches its platform.</p>
            ) : (
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    {cells.map((cell) => (
                        <div key={cell.label} className="border border-gray-200 rounded-lg p-4">
                            <div className="flex items-baseline gap-2">
                                {/* Colour never carries the meaning alone — the
                                    label beside it does. */}
                                <span
                                    className="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0"
                                    style={{ backgroundColor: cell.value > 0 ? cell.color : INK.light.axis }}
                                />
                                <span className="text-2xl font-bold text-gray-900 tabular-nums">{cell.value.toLocaleString()}</span>
                            </div>
                            <p className="text-sm font-medium text-gray-700 mt-1">{cell.label}</p>
                            <p className="text-xs text-gray-500 mt-1">{cell.hint}</p>
                        </div>
                    ))}
                </div>
            )}
        </Section>
    );
};

const TimeToValue = ({ steps }) => (
    <Section
        title="Time to value"
        subtitle="Median days between steps. Medians, not averages — one account that signed up last year and activated yesterday would wreck an average."
    >
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {steps.map((step) => (
                <div key={step.key} className="border border-gray-200 rounded-lg p-4">
                    <p className="text-2xl font-bold text-gray-900 tabular-nums">
                        {step.median_days != null ? `${step.median_days}d` : '—'}
                    </p>
                    <p className="text-sm font-medium text-gray-700 mt-1">{step.label}</p>
                    <p className="text-xs text-gray-400 mt-1">
                        {step.sample > 0 ? `${step.sample.toLocaleString()} accounts` : 'no accounts reached this step'}
                    </p>
                </div>
            ))}
        </div>
    </Section>
);

const FeatureBreadth = ({ breadth, recordingSince }) => {
    const {
        features,
        denominator,
        active_accounts: activeAccounts,
        percentages_meaningful: pctMeaningful,
        unattributed_proposals: unattributed,
    } = breadth;

    // Counts, not percentages. Below a couple of dozen accounts a percentage
    // is theatre — one account moves it ten points — and a 100% bar reads as
    // "everyone uses this" when it means "the one account that did anything
    // touched it". The axis is accounts, capped at the total, so bar length is
    // honestly comparable between features.
    const data = {
        labels: features.map((f) => f.label),
        datasets: [
            {
                label: 'Accounts',
                data: features.map((f) => f.accounts),
                backgroundColor: seriesColor(0),
                borderColor: INK.light.surface,
                borderWidth: 2,
                borderRadius: 4,
                borderSkipped: false,
            },
        ],
    };

    const options = {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        const f = features[ctx.dataIndex];
                        return `${f.accounts.toLocaleString()} of ${denominator.toLocaleString()} accounts${
                            pctMeaningful ? ` (${f.pct}%)` : ''
                        }`;
                    },
                },
            },
        },
        scales: {
            x: {
                beginAtZero: true,
                max: denominator,
                grid: { color: INK.light.grid, drawTicks: false },
                border: { display: false },
                // Whole accounts only — "2.5 accounts" is not a thing.
                ticks: { color: INK.light.muted, precision: 0, stepSize: 1 },
                title: { display: true, text: `accounts (of ${denominator})`, color: INK.light.muted },
            },
            y: {
                grid: { display: false },
                border: { color: INK.light.axis },
                ticks: { color: INK.light.secondary },
            },
        },
    };

    return (
        <Section
            title="Feature adoption"
            subtitle={
                `How many of your ${denominator.toLocaleString()} accounts used each feature this period` +
                (activeAccounts !== undefined
                    ? `. ${activeAccounts.toLocaleString()} had any campaign activity at all, so most bars will be short until more accounts are live.`
                    : '.')
            }
        >
            {denominator === 0 ? (
                <p className="text-sm text-gray-500">No accounts yet.</p>
            ) : (
                <div style={{ height: `${Math.max(240, features.length * 34)}px` }}>
                    <Bar data={data} options={options} />
                </div>
            )}

            <div className="mt-4 overflow-x-auto">
                <table className="min-w-full">
                    <thead>
                        <tr>
                            <th className="px-2 py-1 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Feature</th>
                            <th className="px-2 py-1 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Accounts</th>
                            {pctMeaningful && (
                                <th className="px-2 py-1 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Share</th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {features.map((f) => (
                            <tr key={f.key}>
                                <td className="px-2 py-1 text-sm text-gray-700">{f.label}</td>
                                <td className="px-2 py-1 text-right text-sm tabular-nums text-gray-900">
                                    {f.accounts} <span className="text-gray-400">/ {denominator}</span>
                                </td>
                                {pctMeaningful && (
                                    <td className="px-2 py-1 text-right text-sm tabular-nums text-gray-500">{f.pct}%</td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 space-y-1">
                {/* Say plainly what is missing. A reader who assumes this list is
                    the whole product will draw the wrong conclusion from it. */}
                <p className="text-xs text-gray-500">
                    Only features that already write a row are shown. Read-only surfaces — analytics, the war
                    room, report downloads, copilot volume — leave no trace and are counted from{' '}
                    {recordingSince ? (
                        <span className="font-medium">{recordingSince}</span>
                    ) : (
                        <span className="font-medium">the day recording was instrumented</span>
                    )}
                    , once instrumented.
                </p>
                {unattributed > 0 && (
                    <p className="text-xs text-gray-500">
                        {unattributed.toLocaleString()} proposal{unattributed === 1 ? '' : 's'} in this window belong to a
                        user but no account, and are excluded from the rates above.
                    </p>
                )}
            </div>
        </Section>
    );
};

/**
 * How many features each account touched.
 *
 * The bar chart above answers "which features get used"; this answers "does
 * anyone use more than one". A product where 70% of accounts touch exactly one
 * feature is a very different product from one where they touch four, and the
 * per-feature chart cannot tell those apart.
 */
const BreadthHistogram = ({ histogram }) => {
    const total = histogram.reduce((sum, b) => sum + b.accounts, 0);

    const data = {
        labels: histogram.map((b) => (b.features === 0 ? 'None' : String(b.features))),
        datasets: [
            {
                label: 'Accounts',
                data: histogram.map((b) => b.accounts),
                // Slot 2, so it never reads as the same series as the charts above.
                backgroundColor: seriesColor(1),
                borderColor: INK.light.surface,
                borderWidth: 2,
                borderRadius: 4,
                borderSkipped: false,
            },
        ],
    };

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        const b = histogram[ctx.dataIndex];
                        const pct = total > 0 ? ((b.accounts / total) * 100).toFixed(1) : '0';
                        return `${b.accounts.toLocaleString()} accounts (${pct}%)`;
                    },
                },
            },
        },
        scales: {
            x: {
                grid: { display: false },
                border: { color: INK.light.axis },
                ticks: { color: INK.light.secondary },
                title: { display: true, text: 'Features used', color: INK.light.muted },
            },
            y: {
                beginAtZero: true,
                grid: { color: INK.light.grid, drawTicks: false },
                border: { display: false },
                ticks: { color: INK.light.muted, precision: 0 },
            },
        },
    };

    return (
        <Section
            title="Features per account"
            subtitle="How broadly each account uses the product. A tall bar at zero or one is the number that matters."
        >
            <div style={{ height: '260px' }}>
                <Bar data={data} options={options} />
            </div>
        </Section>
    );
};

export default function EngagementTab({ funnel, statusDrift, timeToValue, featureBreadth, breadthHistogram, coverage }) {
    return (
        <div className="space-y-6">
            <FunnelChart steps={funnel} />

            <StatusDrift drift={statusDrift} />

            {/* One deferred group, so these arrive together in a single request
                rather than three. */}
            <Deferred data="timeToValue" fallback={<Loading label="time to value" />}>
                {() => <TimeToValue steps={timeToValue} />}
            </Deferred>

            <Deferred data="featureBreadth" fallback={<Loading label="feature adoption" />}>
                {() => <FeatureBreadth breadth={featureBreadth} recordingSince={coverage?.recording_since} />}
            </Deferred>

            <Deferred data="breadthHistogram" fallback={<Loading label="breadth histogram" />}>
                {() => <BreadthHistogram histogram={breadthHistogram} />}
            </Deferred>
        </div>
    );
}
