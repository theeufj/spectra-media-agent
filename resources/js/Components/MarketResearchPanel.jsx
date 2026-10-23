const pendingStatuses = ['queued', 'researching'];

export const isResearchPending = (research) => pendingStatuses.includes(research?.status);

export function researchCanBeApproved(research, now = Date.now()) {
    return research?.status === 'ready' && research?.result?.status === 'ready'
        && research.current === true && research.approvable === true && research.approved !== true
        && typeof research.request_id === 'string' && research.request_id.length > 0
        && research.result.fingerprint === research.input_fingerprint
        && Number.isFinite(Date.parse(research.expires_at)) && Date.parse(research.expires_at) > now
        && Number.isSafeInteger(research.result.proposed_daily_budget_micros)
        && research.result.proposed_daily_budget_micros > 0;
}

// A response from an earlier request cannot replace the new research shown by Inertia.
export function latestMarketResearch(initial, polled) {
    if (!polled?.request_id || (initial?.request_id && initial.request_id !== polled.request_id)) return initial;
    if (!initial?.request_id) return polled;
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
const clicks = (value) => numeric(value) ? new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value) : 'Not verified';
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
        <p className="text-xs font-semibold text-gray-500">Traffic estimate</p>
        <dl className="mt-2 grid grid-cols-2 gap-3 text-sm">
            <div><dt className="text-gray-500">Clicks for the period</dt><dd className="font-semibold text-gray-900">{verified ? clicks(forecast.clicks) : 'Not verified'}</dd></div>
            <div><dt className="text-gray-500">Estimated ad spend</dt><dd className="font-semibold text-gray-900">{verified ? money(forecast.cost_micros, currency) : 'Not verified'}</dd></div>
        </dl>
        <p className="mt-2 text-xs text-gray-500">{dates?.from && dates?.through ? `${dates.from} to ${dates.through}` : 'Forecast dates not verified'}{forecast?.period_days ? ` · ${forecast.period_days} days` : ''}{timezone ? ` · ${timezone}` : ''}</p>
    </div>;
}

export default function MarketResearchPanel({ research, currency = 'USD', onRefresh, refreshing = false, now = Date.now(), className = '' }) {
    const pending = isResearchPending(research);
    const expired = research?.approved !== true && research?.expires_at && Date.parse(research.expires_at) <= now;
    const stale = research?.status === 'stale' || research?.current === false || expired;
    const status = stale ? 'stale' : research?.status || 'queued';
    const result = research?.result;
    const titles = { not_requested: 'Preparing your campaign for research', queued: 'Researching your market', researching: 'Researching your market', ready: research?.approved ? 'Approved market plan' : 'Your researched campaign plan', needs_review: 'The campaign plan needs review', unknown: 'Market research could not be verified', stale: 'This research needs refreshing' };
    const reasons = [...asList(research?.reasons), ...asList(result?.reasons), ...(!pending && research?.message ? [research.message] : [])].map(message).filter(Boolean);

    return <section aria-label="Campaign market research" className={`rounded-lg border border-gray-200 bg-white p-5 ${className}`}>
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 className="text-base font-semibold text-gray-900">{titles[status] || titles.unknown}</h3>
                {(pending || !research) && <p role="status" className="mt-1 text-sm text-gray-600">The agents are checking keyword demand, click costs and traffic at a suitable budget before asking you to approve a plan.</p>}
                {status === 'not_requested' && <p role="status" className="mt-1 text-sm text-gray-600">Research starts automatically once your campaign draft and platform account are ready.</p>}
                {stale && <p role="status" className="mt-1 text-sm text-gray-600">Your campaign or the research validity changed. Refresh the evidence before approving a plan.</p>}
                {status === 'needs_review' && <p className="mt-1 text-sm text-gray-600">The agents have not found a plan with enough verified traffic. Review the findings below; a higher budget alone may not help.</p>}
                {status === 'unknown' && <p className="mt-1 text-sm text-gray-600">The agents could not complete a reliable check. Your saved budget has not changed.</p>}
                {numeric(research?.requested_daily_budget_micros) && <p className="mt-2 text-sm text-gray-600">Requested research budget: {money(research.requested_daily_budget_micros, currency)} a day</p>}
            </div>
            {onRefresh && <button type="button" onClick={onRefresh} disabled={pending || refreshing} className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 disabled:opacity-50">{refreshing ? 'Requesting research…' : pending ? 'Research in progress' : status === 'unknown' ? 'Retry research' : 'Refresh research'}</button>}
        </div>
        {reasons.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700">{[...new Set(reasons)].map(reason => <li key={reason}>{reason}</li>)}</ul>}
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
                {asList(strategy.reasons).length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700">{asList(strategy.reasons).map(message).filter(Boolean).map(reason => <li key={reason}>{reason}</li>)}</ul>}
                {caveats.length > 0 && <div className="mt-3 text-xs text-gray-600"><p className="font-semibold">Estimate limitations</p><ul className="mt-1 list-disc space-y-1 pl-5">{[...new Set(caveats)].map(caveat => <li key={caveat}>{caveat}</li>)}</ul></div>}
            </article>;
        })}
        <p className="mt-4 text-xs text-gray-500">Forecast clicks and costs are totals for the dates shown, not guaranteed results. Research does not change your approved spend or switch on your ads.</p>
    </section>;
}
