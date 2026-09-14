import { useCallback, useEffect, useMemo, useState } from 'react';
import { fetchJson } from '@/utils/http';
import { money, count, percent } from '@/utils/format';

/**
 * What this customer's market is worth, wherever they need to know.
 *
 * The funnel is scan → campaign → confirm budget → pay → deploy, and every real
 * signup has stopped at or before the budget step. That step asked for daily
 * spend and said nothing about what the spend buys, while the evidence — real
 * search volume, real top-of-page bids, Google's own 30-day forecast — was
 * already being pulled and shown only to anonymous visitors on the landing page.
 *
 * Two rules this component exists to keep:
 *
 *   1. Every figure here except the conversion rate is Google's measurement of
 *      the customer's own market. The conversion rate is our assumption, so it
 *      is labelled as one, on every variant, always visible rather than behind
 *      a tooltip.
 *   2. Revenue is never invented. Without an order value the money lines are
 *      absent rather than zero, and the panel asks for the number instead of
 *      guessing it — the same figure drives budget reallocation once set.
 *
 * Re-framing for a new budget is arithmetic on figures already fetched, so
 * dragging the budget costs nothing. Only a change of customer refetches.
 */

/** Scale an unconstrained forecast down to a budget — mirrors ForecastFrame. */
export function frameForBudget(forecast, monthlyBudget) {
    if (!forecast) return null;

    const raw = forecast.unconstrained;
    if (!raw || !(monthlyBudget > 0)) return forecast;

    const capped = raw.cost > monthlyBudget;
    const factor = capped ? monthlyBudget / raw.cost : 1;

    const conversions = Math.round(raw.conversions * factor * 10) / 10;
    const cost = Math.round(raw.cost * factor * 100) / 100;

    const framed = {
        ...forecast,
        budget: monthlyBudget,
        budget_capped: capped,
        impressions: Math.round(raw.impressions * factor),
        clicks: Math.round(raw.clicks * factor),
        cost,
        conversions,
    };

    if (forecast.has_order_value && forecast.order_value > 0) {
        const revenue = Math.round(conversions * forecast.order_value * 100) / 100;
        framed.revenue = revenue;
        framed.net = Math.round((revenue - cost) * 100) / 100;
        framed.roas = cost > 0 ? Math.round((revenue / cost) * 100) / 100 : null;
    }

    return framed;
}

function Figure({ label, value, hint, emphasis = false }) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
            <dd className={emphasis ? 'text-2xl font-semibold text-gray-900' : 'text-lg font-semibold text-gray-900'}>
                {value}
            </dd>
            {hint && <p className="text-xs text-gray-500">{hint}</p>}
        </div>
    );
}

function OrderValuePrompt({ currency, monthlyBudget, campaignId, onSaved }) {
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const save = async () => {
        if (!value || saving) return;

        setError(null);
        setSaving(true);

        try {
            const { forecast } = await fetchJson(route('api.forecast.order-value'), {
                method: 'POST',
                json: {
                    average_order_value: value,
                    // Keep the panel on the budget it is already showing.
                    monthly_budget: monthlyBudget > 0 ? monthlyBudget : undefined,
                    campaign_id: campaignId ?? undefined,
                },
            });
            onSaved(forecast);
        } catch {
            // The panel is supplementary — a failure here must not take the
            // page down, only this box.
            setError('We could not save that. Check the amount and try again.');
        } finally {
            setSaving(false);
        }
    };

    /*
     * Not a <form>.
     *
     * This panel renders inside BudgetConfirmation's form, and nested forms are
     * invalid HTML. In this browser the inner form simply never submitted —
     * pressing Enter in the amount field did nothing at all — but the failure
     * mode is not guaranteed to be the harmless one: the enclosing form's
     * submit button is "Confirm budget", which charges seven days of spend up
     * front. Enter in a field asking "what is a customer worth to you?" must
     * never be able to reach it.
     */
    return (
        <div className="mt-4 rounded-md border border-dashed border-gray-300 bg-gray-50 p-4">
            <label htmlFor="forecast-order-value" className="block text-sm font-medium text-gray-900">
                What's one customer worth to you?
            </label>
            <p className="mt-1 text-xs text-gray-600">
                Roughly what you earn from one sale or enquiry. We'll turn the forecast above into
                revenue — and use it to shift budget toward whatever earns most.
            </p>

            <div className="mt-3 flex flex-wrap items-start gap-2">
                {/*
                    The currency sits in its own bordered gutter rather than
                    floating inside the field. Butted straight against the
                    placeholder it read as "AUD 180" — an already-filled value —
                    so people would have skipped the field thinking it was done.
                */}
                <div className="flex rounded-md shadow-sm">
                    <span className="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-100 px-3 text-sm text-gray-600">
                        {currency}
                    </span>
                    <input
                        id="forecast-order-value"
                        type="number"
                        min="1"
                        step="0.01"
                        inputMode="decimal"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                // Stops here rather than bubbling to the budget
                                // form this panel is nested inside.
                                e.preventDefault();
                                e.stopPropagation();
                                save();
                            }
                        }}
                        className="w-32 rounded-none rounded-r-md border-gray-300 text-sm focus:border-brand-primary focus:ring-brand-primary"
                        placeholder="e.g. 180"
                    />
                </div>
                <button
                    type="button"
                    onClick={save}
                    disabled={saving || !value}
                    className="rounded-md bg-brand-dark px-4 py-2 text-sm font-semibold text-white hover:bg-brand-darker disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600"
                >
                    {saving ? 'Saving…' : 'Show me'}
                </button>
            </div>

            {error && <p className="mt-2 text-sm text-red-700">{error}</p>}
        </div>
    );
}

export default function ForecastPanel({
    monthlyBudget = null,
    campaignId = null,
    variant = 'full',
    className = '',
}) {
    const [forecast, setForecast] = useState(null);
    const [state, setState] = useState('loading');

    const load = useCallback(async () => {
        try {
            const params = new URLSearchParams();
            if (campaignId) params.set('campaign_id', campaignId);
            if (monthlyBudget > 0) params.set('monthly_budget', monthlyBudget);

            const query = params.toString();
            const { forecast: result } = await fetchJson(
                route('api.forecast.show') + (query ? `?${query}` : '')
            );

            setForecast(result);
            setState(result ? 'ready' : 'empty');
        } catch {
            setState('empty');
        }
        // monthlyBudget deliberately absent: re-framing is done in the browser
        // from figures already fetched, and refetching per keystroke would
        // spend a pair of Keyword Planner calls each time.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [campaignId]);

    useEffect(() => {
        load();
    }, [load]);

    const framed = useMemo(
        () => frameForBudget(forecast, Number(monthlyBudget)),
        [forecast, monthlyBudget]
    );

    if (state === 'loading') {
        return (
            <div className={`rounded-lg border border-gray-200 bg-white p-6 ${className}`}>
                <p className="text-sm text-gray-500">Checking what your market looks like on Google…</p>
            </div>
        );
    }

    // Nothing to show is not an error worth a red box — the rest of the page is
    // unaffected, and a visitor never asked for this panel specifically.
    if (state === 'empty' || !framed) {
        return null;
    }

    const currency = framed.currency || 'USD';
    const keywords = framed.keywords || [];

    if (variant === 'market') {
        const searches = keywords.reduce((total, k) => total + (k.monthly_searches || 0), 0);
        const bids = keywords.map((k) => k.cpc).filter((c) => c > 0);

        return (
            <div className={`rounded-lg border border-gray-200 bg-white p-5 ${className}`}>
                <h3 className="text-sm font-semibold text-gray-900">Meanwhile — your market on Google</h3>
                <dl className="mt-3 grid grid-cols-2 gap-4">
                    <Figure
                        label="Searches a month"
                        value={count(searches)}
                        hint={`across ${keywords.length} keywords`}
                    />
                    <Figure
                        label="Top-of-page bids"
                        value={bids.length ? `${money(Math.min(...bids), currency)} – ${money(Math.max(...bids), currency)}` : '—'}
                        hint="what it costs to show up"
                    />
                </dl>
                <p className="mt-3 text-xs text-gray-500">Google's own figures for your website.</p>
            </div>
        );
    }

    return (
        <div className={`rounded-lg border border-gray-200 bg-white ${className}`}>
            <div className="border-b border-gray-200 px-5 py-4">
                <h3 className="text-base font-semibold text-gray-900">
                    What {money(framed.budget, currency, { maximumFractionDigits: 0 })} a month buys
                </h3>
                <p className="mt-1 text-sm text-gray-600">
                    Google's forecast for your keywords over {framed.days} days.
                    {framed.budget_capped && ' Your market could absorb more than this budget; these are the figures at your budget.'}
                </p>
            </div>

            <div className="px-5 py-4">
                <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Figure label="Clicks" value={count(framed.clicks)} />
                    <Figure label="Conversions" value={count(framed.conversions)} hint={`at ${percent(framed.conversion_rate * 100)}`} />
                    <Figure label="Ad spend" value={money(framed.cost, currency, { maximumFractionDigits: 0 })} />
                    {framed.has_order_value ? (
                        <Figure
                            label="Revenue"
                            value={money(framed.revenue, currency, { maximumFractionDigits: 0 })}
                            hint={framed.roas ? `${framed.roas}× return` : null}
                            emphasis
                        />
                    ) : (
                        <Figure label="Revenue" value="—" hint="needs your order value" />
                    )}
                </dl>

                {framed.has_order_value && (
                    /*
                       The colour follows the number.

                       This was bg-green-50 whatever the figure said, so a
                       forecast of "-A$689 left after ad spend" was rendered as
                       good news, in the success colour, directly above the
                       button that confirms the budget. At a A$35 order value
                       against A$8–A$40 clicks, a loss is exactly what an honest
                       forecast shows — and showing it in green is the one way
                       to make an honest number lie.
                    */
                    <p className={`mt-4 rounded-md px-3 py-2 text-sm ${framed.net < 0 ? 'bg-amber-50 text-amber-900' : 'bg-green-50 text-green-900'}`}>
                        <span className="font-semibold">
                            {money(framed.net, currency, { maximumFractionDigits: 0 })}
                        </span>{' '}
                        {framed.net < 0
                            ? `short of covering ad spend, at ${money(framed.order_value, currency)} per customer. Worth a higher order value, a better conversion rate, or a lower budget.`
                            : `left after ad spend, at ${money(framed.order_value, currency)} per customer.`}
                    </p>
                )}

                {!framed.has_order_value && (
                    <OrderValuePrompt
                        currency={currency}
                        monthlyBudget={Number(monthlyBudget)}
                        campaignId={campaignId}
                        onSaved={(updated) => updated && setForecast(updated)}
                    />
                )}

                {keywords.length > 0 && (
                    <details className="mt-4">
                        <summary className="cursor-pointer text-sm font-medium text-gray-700">
                            The {keywords.length} keywords this is built from
                        </summary>
                        <ul className="mt-2 divide-y divide-gray-100 text-sm">
                            {keywords.map((k) => (
                                <li key={k.keyword} className="flex items-center justify-between py-1.5">
                                    <span className="text-gray-900">{k.keyword}</span>
                                    <span className="text-gray-500">
                                        {count(k.monthly_searches)}/mo · {money(k.cpc, currency)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}

                {/*
                 * Said plainly rather than tucked into a tooltip. Volume, bids
                 * and the forecast are Google's measurements of this market;
                 * the conversion rate is the one number we supply, and a
                 * forecast is not a promise.
                 */}
                <p className="mt-4 text-xs leading-relaxed text-gray-500">
                    Search volume, bids and the forecast come from Google Ads for your website.
                    The {percent(framed.conversion_rate * 100)} conversion rate is our estimate, not
                    Google's — your real rate depends on your site and your offer.
                    {!framed.country_known && ' We could not match your country, so this uses our default market.'}
                </p>
            </div>
        </div>
    );
}
