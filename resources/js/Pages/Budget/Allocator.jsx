import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { money, count, percent } from '@/utils/format';
import { useCurrency } from '@/hooks/useCurrency';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ChevronDownIcon } from '@heroicons/react/24/outline';

/*
 * Cross-channel budget split.
 *
 * The page used to open on four platform cards and eight number inputs, so the
 * first thing it asked was "what percentage of your money should go to LinkedIn"
 * — of an account with no LinkedIn, no Microsoft and no Facebook. The agent's
 * own answer, which is the reason the page exists, sat two panels down in amber,
 * with a second AI panel in purple underneath saying overlapping things.
 *
 * Reordered to match the decision: what we think you should do, what the
 * platforms are actually returning, and then — folded away — the manual split
 * for someone who wants to overrule it. Platforms the account does not have are
 * not shown at all.
 */

const PLATFORMS = [
    { key: 'google', label: 'Google Ads', field: 'google_ads_pct', snapshot: 'google_ads', bar: 'bg-blue-500', chip: 'bg-blue-100 text-blue-700' },
    { key: 'facebook', label: 'Facebook Ads', field: 'facebook_ads_pct', snapshot: 'facebook_ads', bar: 'bg-indigo-500', chip: 'bg-indigo-100 text-indigo-700' },
    { key: 'microsoft', label: 'Microsoft Ads', field: 'microsoft_ads_pct', snapshot: 'microsoft_ads', bar: 'bg-teal-500', chip: 'bg-teal-100 text-teal-700' },
    { key: 'linkedin', label: 'LinkedIn Ads', field: 'linkedin_ads_pct', snapshot: 'linkedin_ads', bar: 'bg-sky-500', chip: 'bg-sky-100 text-sky-700' },
];

/*
 * Percentages arrive as decimal strings ("100.00"), so a bare {pct}% rendered
 * "100.00%" in the chip and the legend. Trailing zeros are noise on a figure
 * nobody sets to a hundredth of a percent.
 */
function pctLabel(value) {
    const n = parseFloat(value);

    return Number.isFinite(n) ? `${Number(n.toFixed(1))}%` : '—';
}

function PlatformCard({ name, color, data, pct, currency }) {
    const roas = data.roas || 0;
    /*
     * No data is not a bad result.
     *
     * Every platform showed "ROAS 0x" in alarm red on an account that had
     * simply never served an ad. Red is for a return that is genuinely poor,
     * not for the absence of one.
     */
    const hasRoas = Number(data.roas) > 0;
    const roasColor = !hasRoas ? 'text-gray-400'
        : roas >= 3 ? 'text-green-600' : roas >= 1.5 ? 'text-yellow-600' : 'text-red-600';

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-gray-900">{name}</h3>
                <span className={`text-xs px-2 py-0.5 rounded ${color}`}>{pctLabel(pct)}</span>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <div><p className="text-xs text-gray-500">Spend</p><p className="text-sm font-semibold">{money(data.spend ?? 0, currency)}</p></div>
                <div><p className="text-xs text-gray-500">Return on ad spend</p><p className={`text-sm font-semibold ${roasColor}`}>{hasRoas ? `${roas}x` : '—'}</p></div>
                <div><p className="text-xs text-gray-500">Conversions</p><p className="text-sm font-semibold">{count(data.conversions ?? 0)}</p></div>
                <div><p className="text-xs text-gray-500">Cost per conversion</p><p className="text-sm font-semibold">{data.cpa ? money(data.cpa, currency) : '—'}</p></div>
            </div>
            <div className="mt-3"><p className="text-xs text-gray-500">{data.campaigns} campaign{data.campaigns !== 1 ? 's' : ''} · {count(data.clicks ?? 0)} clicks</p></div>
        </div>
    );
}

export default function Allocator({ allocation, snapshot, recommendations, configuredPlatforms = [] }) {
    /*
     * Was read inside PlatformCard only, while the budget field below used a
     * bare `currency` that resolved to nothing — a ReferenceError that took the
     * whole page down to the error boundary. Read once here and passed down.
     */
    const currency = useCurrency();
    const [showManual, setShowManual] = useState(false);

    const { data, setData, put, processing } = useForm({
        total_monthly_budget: allocation?.total_monthly_budget || 1000,
        google_ads_pct: allocation?.google_ads_pct || 100,
        facebook_ads_pct: allocation?.facebook_ads_pct || 0,
        microsoft_ads_pct: allocation?.microsoft_ads_pct || 0,
        linkedin_ads_pct: allocation?.linkedin_ads_pct || 0,
        strategy: allocation?.strategy || 'performance',
        target_roas: allocation?.target_roas || '',
        target_cpa: allocation?.target_cpa || '',
        auto_rebalance: allocation?.auto_rebalance || false,
        rebalance_frequency: allocation?.rebalance_frequency || 'weekly',
    });

    /*
     * Only platforms the account actually has. An account set up on Google
     * alone gets one card and one percentage, which is the whole truth of its
     * split — the other three boxes could only ever be set to zero.
     */
    const active = PLATFORMS.filter(p => configuredPlatforms.includes(p.key));
    const platforms = active.length > 0 ? active : PLATFORMS;
    const singlePlatform = platforms.length === 1;

    const handleSave = (e) => {
        e.preventDefault();
        put(route('budget.update'), { preserveScroll: true });
    };

    const handleRebalance = () => {
        if (confirm('Rebalance now based on performance data?')) {
            router.post(route('budget.rebalance'), {}, { preserveScroll: true });
        }
    };

    const handleApplySuggested = () => {
        if (recommendations?.suggested_splits) {
            setData(prev => ({
                ...prev,
                google_ads_pct: recommendations.suggested_splits.google_ads_pct,
                facebook_ads_pct: recommendations.suggested_splits.facebook_ads_pct,
                microsoft_ads_pct: recommendations.suggested_splits.microsoft_ads_pct,
                linkedin_ads_pct: recommendations.suggested_splits.linkedin_ads_pct || 0,
            }));
        }
    };

    const totalPct = platforms.reduce((sum, p) => sum + parseFloat(data[p.field] || 0), 0);
    const reasoning = recommendations?.ai_reasoning;

    return (
        <AuthenticatedLayout>
            <Head title="Budget split" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl">
                    <div className="flex flex-col gap-4 mb-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            {/* Was "Cross-Channel Budget Allocator". */}
                            <h1 className="text-2xl font-bold text-gray-900">Budget split</h1>
                            <p className="mt-1 text-sm text-gray-600">
                                How your monthly budget is divided between platforms, and what we'd change about it.
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <a href={route('budget.history')} className="inline-flex min-h-[44px] items-center px-4 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50">History</a>
                            <button type="button" onClick={handleRebalance} className="inline-flex min-h-[44px] items-center px-4 text-sm font-medium text-white bg-brand-dark rounded-lg hover:bg-brand-darker">Rebalance now</button>
                        </div>
                    </div>

                    {/*
                        The agent's recommendation, first.

                        It was below the manual inputs and styled as an aside in
                        amber, with a second purple panel under it splitting the
                        same subject across two colours. One panel, in the
                        position the decision is actually made.
                    */}
                    {(recommendations?.suggested_splits || reasoning) && (
                        <div className="mb-8 rounded-xl border border-gray-200 bg-white p-6">
                            <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                                <h2 className="text-base font-semibold text-gray-900">What we'd change</h2>
                                {recommendations?.estimated_improvement_pct > 0 && (
                                    <span className="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded">
                                        +{recommendations.estimated_improvement_pct}% return on ad spend
                                    </span>
                                )}
                            </div>

                            {reasoning?.summary && (
                                <p className="text-sm text-gray-700 mb-3">{reasoning.summary}</p>
                            )}

                            {recommendations?.reasons?.length > 0 && (
                                <ul className="mb-3 space-y-1">
                                    {recommendations.reasons.map((r, i) => (
                                        <li key={i} className="text-sm text-gray-700">• {r}</li>
                                    ))}
                                </ul>
                            )}

                            {reasoning?.insights?.length > 0 && (
                                <ul className="mb-3 space-y-1">
                                    {reasoning.insights.map((insight, i) => (
                                        <li key={i} className="text-sm text-gray-700">• {insight}</li>
                                    ))}
                                </ul>
                            )}

                            {reasoning?.action_items?.length > 0 && (
                                <div className="mb-3">
                                    <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Worth doing</h3>
                                    <ul className="space-y-1">
                                        {reasoning.action_items.map((item, i) => (
                                            <li key={i} className="text-sm text-gray-700">• {item}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {reasoning?.risk_flags?.length > 0 && (
                                <div className="mb-3 rounded-lg border border-red-200 bg-red-50 p-3">
                                    <h3 className="text-xs font-semibold uppercase tracking-wide text-red-700 mb-1">Worth knowing</h3>
                                    <ul className="space-y-1">
                                        {reasoning.risk_flags.map((flag, i) => (
                                            <li key={i} className="text-sm text-red-700">{flag}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {recommendations?.suggested_splits && (
                                <button
                                    type="button"
                                    onClick={handleApplySuggested}
                                    className="inline-flex min-h-[44px] items-center rounded-lg bg-brand-dark px-4 text-sm font-medium text-white transition-colors hover:bg-brand-darker"
                                >
                                    Use this split
                                </button>
                            )}
                        </div>
                    )}

                    {/* What each platform is returning. */}
                    <h2 className="text-base font-semibold text-gray-900 mb-3">Where the money is going</h2>
                    <div className={`grid grid-cols-1 gap-4 mb-8 md:grid-cols-2 ${platforms.length > 2 ? 'lg:grid-cols-4' : ''}`}>
                        {platforms.map(p => (
                            <PlatformCard
                                key={p.key}
                                name={p.label}
                                color={p.chip}
                                data={snapshot?.[p.snapshot] || {}}
                                pct={data[p.field]}
                                currency={currency}
                            />
                        ))}
                    </div>

                    <form onSubmit={handleSave} className="bg-white rounded-lg border border-gray-200 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label htmlFor="total_monthly_budget" className="block text-sm font-medium text-gray-700 mb-1">Total monthly budget</label>
                                <div className="relative">
                                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs font-medium text-gray-500">{currency}</span>
                                    <input
                                        id="total_monthly_budget"
                                        type="number"
                                        step="0.01"
                                        value={data.total_monthly_budget}
                                        onChange={e => setData('total_monthly_budget', e.target.value)}
                                        className="w-full pl-12 rounded-lg border-gray-300 text-sm"
                                    />
                                </div>
                            </div>
                            <div>
                                <label htmlFor="strategy" className="block text-sm font-medium text-gray-700 mb-1">How to divide it</label>
                                <select
                                    id="strategy"
                                    value={data.strategy}
                                    onChange={e => setData('strategy', e.target.value)}
                                    className="w-full rounded-lg border-gray-300 text-sm"
                                >
                                    <option value="performance">Follow what's working</option>
                                    <option value="roas_target">Aim for a set return</option>
                                    <option value="equal">Split evenly</option>
                                    <option value="manual">Exactly as I set below</option>
                                </select>
                            </div>
                        </div>

                        {data.strategy === 'roas_target' && (
                            <div className="mt-4">
                                <label htmlFor="target_roas" className="block text-sm font-medium text-gray-700 mb-1">
                                    Return to aim for
                                </label>
                                <input
                                    id="target_roas"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    value={data.target_roas}
                                    onChange={e => setData('target_roas', e.target.value)}
                                    placeholder="3.0"
                                    className="w-48 rounded-lg border-gray-300 text-sm"
                                />
                                <p className="mt-1 text-xs text-gray-500">
                                    3.0 means $3 of sales for every $1 spent.
                                </p>
                            </div>
                        )}

                        {/*
                            The split itself.

                            Eight number inputs were the first thing on the page;
                            they are now behind a disclosure, because the strategy
                            above already decides this for all but the person who
                            explicitly wants to overrule it. The bar stays visible
                            either way — it is the answer, the inputs are the
                            override.
                        */}
                        <div className="mt-6">
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-sm font-medium text-gray-700">Split</span>
                                <span className={`text-xs ${Math.abs(totalPct - 100) > 0.5 ? 'text-red-600' : 'text-gray-500'}`}>
                                    {percent(totalPct)}
                                </span>
                            </div>
                            <div className="flex h-4 rounded-full overflow-hidden bg-gray-100">
                                {platforms.map(p => (
                                    <div key={p.key} className={p.bar} style={{ width: `${data[p.field]}%` }} />
                                ))}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                {platforms.map(p => (
                                    <span key={p.key} className="inline-flex items-center gap-1.5 text-xs text-gray-600">
                                        <span className={`inline-block h-2 w-2 rounded-full ${p.bar}`} aria-hidden="true" />
                                        {p.label} {pctLabel(data[p.field])}
                                    </span>
                                ))}
                            </div>

                            {!singlePlatform && (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setShowManual(v => !v)}
                                        aria-expanded={showManual}
                                        className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-darker hover:underline"
                                    >
                                        <ChevronDownIcon
                                            className={`h-4 w-4 transition-transform ${showManual ? 'rotate-180' : ''}`}
                                            aria-hidden="true"
                                        />
                                        {showManual ? 'Hide the percentages' : 'Set the percentages myself'}
                                    </button>

                                    {showManual && (
                                        <div className={`mt-3 grid grid-cols-2 gap-4 ${platforms.length > 2 ? 'md:grid-cols-4' : ''}`}>
                                            {platforms.map(p => (
                                                <div key={p.key}>
                                                    <label htmlFor={p.field} className="block text-xs text-gray-600 mb-1">{p.label} %</label>
                                                    <input
                                                        id={p.field}
                                                        type="number"
                                                        step="0.1"
                                                        min="0"
                                                        max="100"
                                                        value={data[p.field]}
                                                        onChange={e => setData(p.field, parseFloat(e.target.value) || 0)}
                                                        className="w-full rounded-lg border-gray-300 text-sm"
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </>
                            )}
                        </div>

                        <div className="mt-6 flex flex-wrap items-center gap-4">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.auto_rebalance}
                                    onChange={e => setData('auto_rebalance', e.target.checked)}
                                    className="rounded border-gray-300 text-brand-dark"
                                />
                                <span className="text-sm text-gray-700">Let the agents adjust this for me</span>
                            </label>
                            {data.auto_rebalance && (
                                <select
                                    value={data.rebalance_frequency}
                                    onChange={e => setData('rebalance_frequency', e.target.value)}
                                    aria-label="How often to adjust"
                                    className="rounded-lg border-gray-300 text-sm"
                                >
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            )}
                        </div>

                        <div className="mt-6 flex justify-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex min-h-[44px] items-center rounded-lg bg-brand-dark px-6 text-sm font-medium text-white transition-colors hover:bg-brand-darker disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-600"
                            >
                                {processing ? 'Saving…' : 'Save'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
