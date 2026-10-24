import { Link } from '@inertiajs/react';
import { usePolling } from '@/hooks/usePolling';

const pendingStatuses = ['queued', 'researching'];

export const isResearchPending = (research) => pendingStatuses.includes(research?.status);

export function researchBudgetOptions(research) {
    return (Array.isArray(research?.result?.budget_options) ? research.result.budget_options : []).filter(option =>
        /^[a-f0-9]{64}$/.test(option?.id || '')
        && Number.isSafeInteger(option.daily_budget_micros) && option.daily_budget_micros >= 5_000_000
        && option.daily_budget_micros <= 10_000_000_000 && option.daily_budget_micros % 10_000 === 0
        && Number.isSafeInteger(option.cpc_bid_micros) && option.cpc_bid_micros > 0
        && option.proposal?.proposed_daily_budget_micros === option.daily_budget_micros
        && option.proposal?.fingerprint === research.input_fingerprint
        && option.forecast?.success === true
        && Number.isFinite(option.forecast.clicks) && option.forecast.clicks > 0
        && Number.isSafeInteger(option.forecast.cost_micros) && option.forecast.cost_micros >= 0
        && Number.isSafeInteger(option.forecast.period_days) && option.forecast.period_days > 0
        && /^\d{4}-\d{2}-\d{2}$/.test(option.forecast.forecast_period?.from || '')
        && /^\d{4}-\d{2}-\d{2}$/.test(option.forecast.forecast_period?.through || '')
    ).sort((a, b) => a.daily_budget_micros - b.daily_budget_micros);
}

export function researchCanBeApproved(research, now = Date.now()) {
    const options = research?.result?.budget_options;
    return research?.status === 'ready' && research?.result?.status === 'ready'
        && research.current === true && research.approvable === true && research.approved !== true
        && typeof research.request_id === 'string' && research.request_id.length > 0
        && research.result.fingerprint === research.input_fingerprint
        && Number.isFinite(Date.parse(research.expires_at)) && Date.parse(research.expires_at) > now
        && Number.isSafeInteger(research.result.proposed_daily_budget_micros)
        && research.result.proposed_daily_budget_micros > 0
        && (!Array.isArray(options) || options.length === researchBudgetOptions(research).length);
}

// A response from an earlier request cannot replace the new research shown by Inertia.
export function latestMarketResearch(initial, polled) {
    if (!polled?.request_id || (initial?.request_id && initial.request_id !== polled.request_id)) return initial;
    if (!initial?.request_id) return polled;
    if (initial.approved === true) return initial;
    const initialTime = Date.parse(initial.checked_at || initial.requested_at || '') || 0;
    const polledTime = Date.parse(polled.checked_at || polled.requested_at || '') || 0;
    if (initialTime > polledTime) return initial;
    if (initialTime === polledTime && initial.input_fingerprint !== polled.input_fingerprint) return initial;
    if (initialTime === polledTime && ['needs_review', 'unknown', 'stale'].includes(initial.status)
        && polled.status === 'ready') return initial;
    return polled;
}

const asList = (value) => Array.isArray(value) ? value : [];
const message = (value) => typeof value === 'string' ? value : value?.message || value?.reason || '';
const numeric = (value) => typeof value === 'number' && Number.isFinite(value) && value >= 0;
const money = (micros, currency) => numeric(micros) ? `${currency} ${(micros / 1_000_000).toFixed(2)}` : 'Not verified';
const clicks = (value) => numeric(value) ? value > 0 && value < 0.01 ? '<0.01' : new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value) : 'Not verified';
const averageCpc = (forecast, currency) => forecast?.success === true
    && Number.isSafeInteger(forecast.cost_micros) && forecast.cost_micros > 0
    && numeric(forecast.clicks) && forecast.clicks > 0
    ? money(forecast.cost_micros / forecast.clicks, currency) : 'Not verified';
const costEfficientReach = (strategy) => strategy?.evidence?.selection_policy === 'cost_efficient_reach'
    || strategy?.evidence?.budget_option_forecast?.selection_policy === 'cost_efficient_reach';
const limitedTraffic = (strategy, forecast = strategy?.forecast) => forecast?.success === true
    && numeric(forecast.clicks) && forecast.clicks > 0
    && (strategy?.traffic_status === 'limited' || strategy?.meets_traffic_threshold === false
        || forecast.traffic_status === 'limited' || forecast.meets_traffic_threshold === false
        || strategy?.evidence?.traffic_status === 'limited' || strategy?.evidence?.meets_traffic_threshold === false);
const biddingMode = (value) => {
    const mode = typeof value === 'string' ? value.replace(/([a-z])([A-Z])/g, '$1_$2').replace(/\s+/g, '_').toUpperCase() : '';
    return ({ MANUAL_CPC: 'Manual cost-per-click bidding', MAXIMIZE_CONVERSIONS: 'Maximize conversions', TARGET_CPA: 'Target cost per conversion', TARGET_SPEND: 'Maximize clicks' })[mode] || 'Not verified';
};

function Keywords({ values }) {
    const keywords = asList(values);
    if (!keywords.length) return <p className="text-sm text-gray-500">No verified keyword set yet.</p>;
    return <ul className="space-y-1 text-sm text-gray-700">{keywords.map((keyword, index) => {
        const text = typeof keyword === 'string' ? keyword : keyword.text || keyword.keyword || '';
        return <li key={`${text}-${index}`}><span>{text}</span>{keyword.match_type && <span className="ml-2 text-xs text-gray-500">{keyword.match_type}</span>}{keyword.selection_reason && <p className="text-xs text-gray-500">{keyword.selection_reason}</p>}</li>;
    })}</ul>;
}

function Forecast({ forecast, period, timezone, currency }) {
    const verified = forecast?.success === true;
    const dates = period || forecast?.forecast_period;
    return <div className="mt-3 border-t border-gray-100 pt-3">
        <p className="text-xs font-semibold text-gray-500">Indicative traffic estimate</p>
        <dl className="mt-2 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            <div><dt className="text-gray-500">Clicks for the period</dt><dd className="font-semibold text-gray-900">{verified ? clicks(forecast.clicks) : 'Not verified'}</dd></div>
            <div><dt className="text-gray-500">Estimated ad spend</dt><dd className="font-semibold text-gray-900">{verified ? money(forecast.cost_micros, currency) : 'Not verified'}</dd></div>
            <div><dt className="text-gray-500">Estimated average CPC</dt><dd className="font-semibold text-gray-900">{averageCpc(forecast, currency)}</dd></div>
        </dl>
        <p className="mt-2 text-xs text-gray-500">{dates?.from && dates?.through ? `${dates.from} to ${dates.through}` : 'Forecast dates not verified'}{forecast?.period_days ? ` · ${forecast.period_days} days` : ''}{timezone ? ` · ${timezone}` : ''}</p>
    </div>;
}

export default function MarketResearchPanel({ research: initialResearch, statusUrl = null, customBudgetUrl = null, currency = 'USD', onRefresh, refreshing = false, now = Date.now(), className = '', onSelectBudgetOption, selectedBudgetOptionId, selectingDisabled = false }) {
    const poll = usePolling(statusUrl, {
        interval: 5000,
        enabled: Boolean(statusUrl) && isResearchPending(initialResearch),
        restartKey: initialResearch?.request_id || '',
        parse: response => {
            if (typeof response?.marketResearch?.status !== 'string') throw new Error('Invalid market research status');
            return response;
        },
        until: response => response.marketResearch?.request_id === initialResearch?.request_id
            && !isResearchPending(response.marketResearch),
    });
    const research = statusUrl ? latestMarketResearch(initialResearch, poll.data?.marketResearch) : initialResearch;
    const pending = isResearchPending(research);
    const expired = research?.approved !== true && research?.expires_at && Date.parse(research.expires_at) <= now;
    const stale = research?.status === 'stale' || research?.current === false || expired;
    const status = stale ? 'stale' : research?.status || 'queued';
    const result = research?.result;
    const options = researchBudgetOptions(research);
    const unavailableComparisons = asList(result?.budget_comparisons).filter(comparison => comparison.status === 'unavailable'
        && Number.isSafeInteger(comparison.daily_budget_micros) && comparison.daily_budget_micros > 0);
    const lowestOption = options[0];
    const littleBudgetGain = options.length > 1 && options.slice(1).every(option =>
        option.forecast.forecast_period.from === lowestOption.forecast.forecast_period.from
        && option.forecast.forecast_period.through === lowestOption.forecast.forecast_period.through
        && option.forecast.clicks <= lowestOption.forecast.clicks * 1.1);
    const selectedOptionId = selectedBudgetOptionId === undefined ? result?.selected_budget_option_id : selectedBudgetOptionId;
    const existingRecommendation = status === 'needs_review' && asList(result?.strategies).some(strategy =>
        strategy.proposal_status === 'ready' && strategy.requires_live_review === true);
    const titles = { not_requested: 'Preparing your campaign for research', queued: 'Researching your market', researching: 'Researching your market', ready: research?.approved ? 'Approved market plan' : 'Your researched campaign plan', needs_review: 'The campaign plan needs review', unknown: 'Market research could not be verified', stale: 'This research needs refreshing' };
    const reasons = [...asList(research?.reasons), ...asList(result?.reasons), ...(!pending && research?.message ? [research.message] : [])].map(message).filter(Boolean);

    return <section aria-label="Campaign market research" className={`rounded-lg border border-gray-200 bg-white p-5 ${className}`}>
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 className="text-base font-semibold text-gray-900">{existingRecommendation ? 'A researched recommendation needs review' : titles[status] || titles.unknown}</h3>
                {(pending || !research) && <p role="status" className="mt-1 text-sm text-gray-600">The agents are checking keyword demand, click costs and traffic at a suitable budget before asking you to approve a plan.</p>}
                {status === 'not_requested' && <p role="status" className="mt-1 text-sm text-gray-600">Research starts automatically once your campaign draft and platform account are ready.</p>}
                {stale && <p role="status" className="mt-1 text-sm text-gray-600">Your campaign or the research validity changed. Refresh the evidence before approving a plan.</p>}
                {status === 'needs_review' && <p className="mt-1 text-sm text-gray-600">{existingRecommendation
                    ? 'The agents found a recommendation with a tested traffic estimate. Changes to an existing campaign need a separate review before they can be applied.'
                    : 'The agents could not verify a positive traffic estimate for every placement. Review the findings below; a higher budget alone may not help.'}</p>}
                {status === 'unknown' && <p className="mt-1 text-sm text-gray-600">The agents could not complete a reliable check. Your saved budget has not changed.</p>}
                {numeric(research?.requested_daily_budget_micros) && <p className="mt-2 text-sm text-gray-600">Requested research budget: {money(research.requested_daily_budget_micros, currency)} a day</p>}
            </div>
            {onRefresh && <button type="button" onClick={onRefresh} disabled={pending || refreshing} className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 disabled:opacity-50">{refreshing ? 'Requesting research…' : pending ? 'Research in progress' : status === 'unknown' ? 'Retry research' : 'Refresh research'}</button>}
        </div>
        {statusUrl && poll.error && pending && <p role="alert" className="mt-3 text-sm text-amber-800">We cannot confirm the latest research status. Reload this page to check again; your saved budget has not changed.</p>}
        {reasons.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700">{[...new Set(reasons)].map(reason => <li key={reason}>{reason}</li>)}</ul>}
        {!pending && !stale && options.length > 0 && <fieldset className="mt-5" disabled={selectingDisabled}>
            <legend className="text-sm font-semibold text-gray-900">{onSelectBudgetOption ? 'Choose a researched daily budget' : 'Researched daily budget options'}</legend>
            <p className="mt-1 text-sm text-gray-600"><strong>Indicative estimates.</strong> Each option uses the same keyword set, with a researched maximum CPC for each daily budget. Google forecasts clicks and ad spend for the dates shown; actual results can vary. Click counts are rounded.</p>
            {result?.requires_explicit_option_selection === true && !selectedOptionId && <p role="status" className="mt-2 text-sm text-gray-700">No estimate is available for your starting amount. Choose an available researched option or research another amount. No budget has been selected.</p>}
            {asList(result?.strategies).some(costEfficientReach) && <p className="mt-2 text-sm text-gray-600">Bids favour lower estimated cost per click for the forecast traffic. Estimates do not establish sales or profitability.</p>}
            {littleBudgetGain && <div role="note" className="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                <p className="font-semibold">Higher budgets add little forecast traffic</p>
                <p className="mt-1">Google forecasts little or no extra traffic across these researched budgets and maximum CPC bids for this keyword set. A higher spending limit does not guarantee more clicks.</p>
            </div>}
            <div className="mt-3 grid gap-3 sm:grid-cols-3">{options.map((option, index) => {
                const code = option.currency_code || currency;
                const previous = options[index - 1];
                const samePeriod = previous?.forecast?.forecast_period?.from === option.forecast.forecast_period?.from
                    && previous?.forecast?.forecast_period?.through === option.forecast.forecast_period?.through;
                const littleGain = previous && samePeriod && option.forecast.clicks <= previous.forecast.clicks * 1.1;
                const content = <>
                    <span className="flex items-center gap-2">
                        {onSelectBudgetOption && <input type="radio" name="market-budget-option" value={option.id} checked={selectedOptionId === option.id} onChange={() => onSelectBudgetOption(option.id)} aria-label={`${money(option.daily_budget_micros, code)} per day`} className="text-brand-dark focus:ring-brand-dark" />}
                        <span className="font-semibold text-gray-900">{money(option.daily_budget_micros, code)} / day</span>
                    </span>
                    <span className="mt-2 block text-sm text-gray-700">Maximum CPC: {money(option.cpc_bid_micros, code)}</span>
                    <span className="mt-1 block text-sm text-gray-700">Estimated average CPC: {averageCpc(option.forecast, code)}</span>
                    <span className="mt-3 block text-sm text-gray-700">About {option.forecast.clicks < 1 ? clicks(option.forecast.clicks) : Math.round(option.forecast.clicks)} clicks</span>
                    <span className="mt-1 block text-sm text-gray-700">{money(option.forecast.cost_micros, code)} estimated ad spend</span>
                    <span className="mt-1 block text-xs text-gray-500">{option.forecast.forecast_period?.from} to {option.forecast.forecast_period?.through}{option.forecast.period_days ? ` · ${option.forecast.period_days} days` : ''}</span>
                    {asList(option.proposal?.strategies).some(strategy => limitedTraffic(strategy, option.forecast)) && <span className="mt-2 block text-xs text-amber-800"><strong>Limited forecast traffic.</strong> This budget may not be fully spent.</span>}
                    <span className="mt-2 block text-xs font-medium text-brand-dark">{option.daily_budget_micros === research.requested_daily_budget_micros ? 'Custom budget estimate' : index === 0 ? 'Lowest tested budget' : !samePeriod ? 'Different forecast dates' : littleGain ? 'Little extra traffic for the higher budget' : 'More estimated traffic'}</span>
                </>;
                const style = `block rounded-lg border p-4 ${selectedOptionId === option.id ? 'border-brand-dark bg-gray-50' : 'border-gray-200'} ${onSelectBudgetOption ? 'cursor-pointer' : ''}`;
                return onSelectBudgetOption ? <label key={option.id} className={style}>{content}</label> : <div key={option.id} className={style}>{content}</div>;
            })}</div>
            {onSelectBudgetOption && <p className="mt-2 text-xs text-gray-600">Choosing an option updates your proposal. Your saved budget changes only after you approve it.</p>}
        </fieldset>}
        {!pending && !stale && unavailableComparisons.length > 0 && <div className="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
            <h4 className="text-sm font-semibold text-gray-900">Amounts without a verified estimate</h4>
            <ul className="mt-2 space-y-2 text-sm text-gray-700">{unavailableComparisons.map(comparison => <li key={comparison.daily_budget_micros}>
                <span className="font-semibold">{money(comparison.daily_budget_micros, currency)} / day</span>
                <span className="ml-2">{comparison.forecast?.success === true && numeric(comparison.forecast.clicks)
                    ? `${clicks(comparison.forecast.clicks)} forecast clicks` : 'Traffic estimate unavailable'}</span>
                {message(comparison.reason) && <p className="mt-1 text-xs text-gray-600">{message(comparison.reason)}</p>}
            </li>)}</ul>
            <p className="mt-2 text-xs text-gray-600">These amounts cannot be selected from this research. You can request another estimate.</p>
        </div>}
        {customBudgetUrl && <div className="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
            <Link href={customBudgetUrl} className="text-sm font-semibold text-brand-dark underline">Research a custom amount</Link>
            <p className="mt-1 text-sm text-gray-600">Try another daily budget, such as {currency} 50, for an indicative forecast. Researching an amount does not change your saved budget or switch on ads.</p>
        </div>}
        {!pending && !stale && asList(result?.strategies).map((strategy, index) => {
            const code = strategy.currency_code || currency;
            const caveats = [...asList(strategy.caveats), ...asList(strategy.forecast?.caveats)].map(message).filter(Boolean);
            if (strategy.status === 'preserved') return <article key={strategy.strategy_id || index} className="mt-5 rounded-lg border border-gray-200 p-4">
                <h4 className="font-semibold text-gray-900">{strategy.platform || 'Ad platform'}{strategy.channel ? ` · ${strategy.channel}` : ''}</h4>
                <h5 className="mt-3 text-sm font-semibold text-gray-700">Existing campaign — allocation retained</h5>
                <p className="mt-2 text-sm text-gray-700">Daily budget: {money(strategy.current_budget_micros, code)}</p>
                <p className="mt-1 text-sm text-gray-600">This proposal keeps the existing campaign's allocation and settings.</p>
                {asList(strategy.current_keywords).length > 0 && <div className="mt-3"><p className="mb-2 text-sm font-semibold text-gray-700">Current keywords</p><Keywords values={strategy.current_keywords} /></div>}
            </article>;
            return <article key={strategy.strategy_id || index} className="mt-5 rounded-lg border border-gray-200 p-4">
                <h4 className="font-semibold text-gray-900">{strategy.platform || 'Ad platform'}{strategy.channel ? ` · ${strategy.channel}` : ''}</h4>
                <div className="mt-3 grid gap-5 sm:grid-cols-2">
                    <div><h5 className="text-sm font-semibold text-gray-700">Current plan</h5><p className="mt-2 text-sm text-gray-700">Daily budget: {money(strategy.current_budget_micros, code)}</p><p className="text-sm text-gray-700">Bidding: {biddingMode(strategy.current_bidding_strategy)}</p><p className="mb-3 text-sm text-gray-700">CPC limit: {money(strategy.current_cpc_bid_micros, code)}</p><Keywords values={strategy.current_keywords} /></div>
                    <div><h5 className="text-sm font-semibold text-gray-700">Proposed plan</h5><p className="mt-2 text-sm text-gray-700">Daily budget: {money(strategy.budget_micros, code)}</p><p className="text-sm text-gray-700">Bidding: {biddingMode(strategy.bidding_strategy)}</p><p className="mb-3 text-sm text-gray-700">CPC limit: {money(strategy.cpc_bid_micros, code)}</p><Keywords values={strategy.proposed_keywords} /><Forecast forecast={strategy.forecast} period={strategy.forecast_period} timezone={strategy.time_zone} currency={code} /></div>
                </div>
                {limitedTraffic(strategy) && <div role="note" className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p className="font-semibold">Limited forecast traffic</p>
                    <p className="mt-1">Google forecasts {clicks(strategy.forecast.clicks)} clicks{strategy.forecast.forecast_period?.from && strategy.forecast.forecast_period?.through
                        ? ` from ${strategy.forecast.forecast_period.from} to ${strategy.forecast.forecast_period.through}` : ' for the forecast period'}. Your budget may not be fully spent. You can choose this estimate or research another amount; sales and profitability are not verified.</p>
                </div>}
                {asList(strategy.reasons).length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700">{asList(strategy.reasons).map(message).filter(Boolean).map(reason => <li key={reason}>{reason}</li>)}</ul>}
                {caveats.length > 0 && <div className="mt-3 text-xs text-gray-600"><p className="font-semibold">Estimate limitations</p><ul className="mt-1 list-disc space-y-1 pl-5">{[...new Set(caveats)].map(caveat => <li key={caveat}>{caveat}</li>)}</ul></div>}
            </article>;
        })}
        <p className="mt-4 text-xs text-gray-500">These forecasts are indicative estimates for the dates shown. Clicks, costs and purchases are not guaranteed. Research does not change your approved spend or switch on your ads.</p>
    </section>;
}
