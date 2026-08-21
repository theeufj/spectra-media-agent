import { router, Link } from '@inertiajs/react';
import AdminShell from './AdminShell';
import MetricCard from '@/Components/MetricCard';
import EngagementTab from './Usage/EngagementTab';
import '@/Components/Charts/registerCharts';

/**
 * The admin landing page.
 *
 * Tab and period live in the URL, not in React state, so the back button,
 * deep links and a shared link all work. Both are validated server-side against
 * an allowlist — see UsageDashboardController.
 */
export default function UsageDashboard({
    tab,
    tabs,
    period,
    periodOptions,
    summary,
    coverage,
    funnel,
    statusDrift,
    timeToValue,
    featureBreadth,
    breadthHistogram,
}) {
    const navigate = (params) =>
        router.get(route('admin.dashboard'), { tab, period: period.key, ...params }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const periodPicker = (
        <div className="flex gap-2 flex-wrap">
            {periodOptions.map((option) => (
                <button
                    key={option.value}
                    onClick={() => navigate({ period: option.value })}
                    className={`px-3 py-2 text-sm font-medium rounded-lg transition-colors ${
                        period.key === option.value
                            ? 'bg-flame-orange-500 text-white'
                            : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'
                    }`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );

    return (
        <AdminShell
            title="Usage & Adoption"
            heading="Usage & Adoption"
            subheading="How customers actually use the platform."
            actions={periodPicker}
        >
            <div className="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
                {summary.map((card) => (
                    <MetricCard
                        key={card.key}
                        title={card.label}
                        value={card.value.toLocaleString()}
                        subtitle={card.sub}
                        trend={card.trend}
                    />
                ))}
            </div>

            {/* What these numbers do and do not cover, stated rather than
                implied. Without this note "active accounts" reads as a complete
                measure of engagement, and it is not one yet. */}
            <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                <p className="text-xs text-amber-900">
                    <span className="font-semibold">What "active" means here:</span> write actions only —
                    creating or changing a campaign, generating creative, running an audit. Someone who logs in
                    daily to read dashboards and changes nothing does not appear, so dormancy is over-reported.
                    {coverage?.recording_since
                        ? ` Read-only usage has been recorded since ${coverage.recording_since}.`
                        : ' Read-only usage recording has just been switched on and has no history yet.'}{' '}
                    Login events are only retained for {coverage?.activity_log_retention_days ?? 30} days.
                </p>
            </div>

            <div className="border-b border-gray-200 mb-6">
                <nav className="flex gap-1 -mb-px">
                    {tabs.map((t) => {
                        const active = t.key === tab;

                        // Phase 2 and 3 tabs are shown disabled rather than
                        // hidden, so it is visible what is coming and visible
                        // that the gap is deliberate.
                        return (
                            <button
                                key={t.key}
                                disabled={!t.enabled}
                                onClick={() => t.enabled && navigate({ tab: t.key })}
                                title={t.enabled ? undefined : 'Not built yet'}
                                className={`px-4 py-3 text-sm font-medium border-b-2 transition-colors ${
                                    active
                                        ? 'border-flame-orange-500 text-flame-orange-600'
                                        : t.enabled
                                          ? 'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300'
                                          : 'border-transparent text-gray-300 cursor-not-allowed'
                                }`}
                            >
                                {t.label}
                                {!t.enabled && <span className="ml-2 text-xs font-normal">soon</span>}
                            </button>
                        );
                    })}
                </nav>
            </div>

            {tab === 'engagement' && (
                <EngagementTab
                    funnel={funnel}
                    statusDrift={statusDrift}
                    timeToValue={timeToValue}
                    featureBreadth={featureBreadth}
                    breadthHistogram={breadthHistogram}
                    coverage={coverage}
                />
            )}

            {/* This page reports; the pages below own their subjects. Linking
                out is what keeps it from becoming a fifth partial
                reimplementation of them. */}
            <div className="mt-8 border-t border-gray-200 pt-4">
                <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Deep dives</p>
                <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    <Link href={route('admin.revenue.index')} className="text-flame-orange-600 hover:underline">Revenue &amp; MRR →</Link>
                    <Link href={route('admin.ai-costs.index')} className="text-flame-orange-600 hover:underline">AI cost breakdown →</Link>
                    <Link href={route('admin.execution.metrics')} className="text-flame-orange-600 hover:underline">Deployment execution →</Link>
                    <Link href={route('admin.automation-health')} className="text-flame-orange-600 hover:underline">Automation health →</Link>
                    <Link href={route('admin.activity.index')} className="text-flame-orange-600 hover:underline">Activity log →</Link>
                </div>
            </div>
        </AdminShell>
    );
}
