import { useEffect, useState } from 'react';
import { dateTime } from '@/utils/format';
import { usePolling } from '@/hooks/usePolling';

const statuses = {
    collecting_evidence: ['Gathering delivery evidence', 'We are measuring a complete reporting period before deciding whether a repair is needed.'],
    delivering: ['Ads are receiving traffic', 'Google reported traffic in the measured period. This does not confirm that the campaign is generating signups or sales.'],
    low_reach: ['Limited search reach', 'The campaign is receiving little traffic. The diagnosis below explains the constraints and possible next steps.'],
    approval_required: ['Changes need your approval', 'The proposed changes have not been applied. Review them with your administrator before changing the campaign.'],
    repairing: ['Applying a delivery repair', 'A repair is in progress. Its effect on traffic has not been confirmed yet.'],
    verifying: ['Checking traffic after the repair', 'The changes are in place. We are waiting for measured traffic before calling the repair successful.'],
    recovered: ['Traffic recovery confirmed', 'Google reported traffic after the repair. Signups and sales still need to be assessed separately.'],
    needs_review: ['Delivery needs review', 'The campaign needs attention. Review the measured results and proposed next steps below.'],
    unavailable: ['Delivery check unavailable', 'The last check could not confirm the current delivery diagnosis. These figures do not establish that the campaign is healthy.'],
    paused: ['Campaign is paused', 'The campaign is paused. Automatic delivery repairs will not restart it or change its approved spending limits.'],
};

const holdReasons = {
    approved_bounded_trial: 'Automatic changes are on hold to preserve the approved test. Keyword research and delivery checks continue.',
    active_trial: 'Automatic changes are on hold to preserve the approved test. Keyword research and delivery checks continue.',
    spend_safety_hold: 'Spending is on hold for review. Research does not restart the campaign or increase its spending limits.',
    campaign_not_active: 'The campaign is inactive. Research does not enable it or change its budget.',
    google_campaign_paused: 'Google has paused the campaign. Research does not restart it.',
    campaign_paused: 'The campaign is paused. Research does not restart it.',
    setup_only: 'This one-time setup does not include automatic ongoing campaign changes.',
    auto_healing_disabled: 'Automatic repairs are turned off. The diagnosis remains available for review.',
    approval_required: 'Review and approve the proposed changes before they can be applied.',
    automatic_management_not_enabled: 'Automatic campaign management is not enabled for this campaign. Review the diagnosis before making changes.',
    approved_budget_unverified: 'The approved daily budget needs to be verified before automatic repairs can run.',
    existing_cpc_cap_required: 'A verified maximum cost-per-click bid is required before a keyword repair can be applied.',
    forecasted_relevant_keywords_required: 'No suitable keyword additions have been verified to improve reach within the current bid and budget limits.',
    repair_attempt_limit: 'This campaign has reached its automatic repair attempt limit. Review the results before approving another change.',
    repair_did_not_restore_search_traffic: 'The repair has not restored measured Google Search traffic. Review the targeting, keywords and bid limit before trying again.',
    partial_repair_unresolved: 'Some repair changes could not be confirmed. Review those changes before another repair is attempted.',
};

function holdReason(reason) {
    if (typeof reason !== 'string') return null;
    return holdReasons[reason.trim().toLowerCase().replace(/[ _]+/g, '_')] || null;
}

function reviewReason(reason) {
    return holdReason(reason) || reason;
}

function observedNumber(value) {
    if (value == null || value === '' || typeof value === 'boolean') return null;
    const result = Number(value);
    return Number.isFinite(result) && result >= 0 ? result : null;
}

function measuredCount(value) {
    const result = observedNumber(value);
    return result === null ? 'Not available' : new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(result);
}

function measuredSpend(value, currency) {
    const result = observedNumber(value);
    if (result === null) return 'Not available';
    const amount = result / 1_000_000;
    if (!/^[A-Z]{3}$/.test(currency || '')) return `${amount.toFixed(2)} in ad account currency`;
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, currencyDisplay: 'code' }).format(amount);
}

function validTime(value) {
    return typeof value === 'string' && value !== '' && Number.isFinite(Date.parse(value));
}

function Time({ value }) {
    return validTime(value) ? <time dateTime={value}>{dateTime(value)}</time> : 'Not available';
}

function ForecastEvidence({ forecast, label, currency }) {
    if (!forecast || typeof forecast !== 'object' || !Object.keys(forecast).length) return null;
    return <div className="mt-3 rounded-lg border border-gray-200 bg-white p-3">
        <h4 className="text-sm font-medium text-gray-900">{label}</h4>
        {typeof forecast.summary === 'string' && <p className="mt-1 text-sm text-gray-700">{forecast.summary}</p>}
        {forecast.success === false && <p className="mt-1 text-sm text-gray-700">A fresh forecast could not be confirmed. Proposed changes still need review.</p>}
        {forecast.success === true && <>
            {observedNumber(forecast.period_days) > 0 && <p className="mt-1 text-xs text-gray-600">Estimate over {measuredCount(forecast.period_days)} days at the assessed bid and targeting.</p>}
            <dl className="mt-2 grid grid-cols-3 gap-3 text-sm">
                {[['Estimated impressions', measuredCount(forecast.impressions)], ['Estimated clicks', measuredCount(forecast.clicks)],
                    ['Estimated ad spend', measuredSpend(forecast.cost_micros, forecast.context?.currency_code || currency)]].map(([name, value]) => <div key={name}>
                    <dt className="text-xs text-gray-600">{name}</dt><dd className="mt-1 font-medium text-gray-900">{value}</dd>
                </div>)}
            </dl>
            {forecast.auto_repair_safe === false && <p className="mt-2 text-xs text-gray-700">This estimate does not fully account for all live restrictions. Changes need review.</p>}
        </>}
    </div>;
}

export function searchDeliveryStatus(state, now = Date.now()) {
    if (!state) return null;
    if (state.error || state.errors?.length || state.status === 'unavailable') return 'unavailable';
    const configuredHours = observedNumber(state.stale_after_hours);
    const staleHours = configuredHours > 0 ? configuredHours : 3;
    if (state.stale === true || !validTime(state.checked_at) || now - Date.parse(state.checked_at) > staleHours * 3_600_000) return 'needs_review';
    if (state.repair?.errors?.length || state.verification?.errors?.length) return 'needs_review';
    if (state.status === 'recovered' && !(observedNumber(state.verification?.impressions ?? state.verification?.measurement?.impressions) > 0)) return 'verifying';
    return Object.hasOwn(statuses, state.status) ? state.status : 'unavailable';
}

export default function SearchDeliveryCard({ campaign }) {
    const initial = campaign?.search_delivery_state;
    const [now, setNow] = useState(Date.now);
    const [refreshAttempt, setRefreshAttempt] = useState(0);
    const { data: refreshed, error: refreshError } = usePolling(
        initial && campaign.uuid ? route('api.campaigns.show', { campaign: campaign.uuid }) : null,
        {
            interval: 60_000,
            enabled: Boolean(initial && campaign.uuid),
            immediate: refreshAttempt > 0,
            restartKey: refreshAttempt,
            parse: value => {
                if (!value || value.uuid !== campaign.uuid || !Object.hasOwn(value, 'search_delivery_state')) throw new Error('Delivery status could not be refreshed.');
                return value;
            },
        }
    );
    useEffect(() => {
        if (!initial) return undefined;
        const timer = setInterval(() => setNow(Date.now()), 60_000);
        return () => clearInterval(timer);
    }, [Boolean(initial)]);
    if (!initial) return null;
    const hasMatchingRefresh = Boolean(refreshed && campaign?.uuid && refreshed.uuid === campaign.uuid);
    const latest = hasMatchingRefresh ? refreshed.search_delivery_state : initial;
    // Inertia may return a newer state without remounting this card. Keep the
    // newest evidence rather than overwriting it with an older poll response.
    const initialIsNewer = validTime(initial?.checked_at) && validTime(latest?.checked_at) && Date.parse(initial.checked_at) > Date.parse(latest.checked_at);
    const observed = hasMatchingRefresh && !latest ? { ...initial, status: 'unavailable' } : initialIsNewer ? initial : latest;
    const state = refreshError && observed ? { ...observed, status: 'unavailable' } : observed;
    const status = searchDeliveryStatus(state, now);
    if (!status) return null;
    const measurement = state.measurement || {};
    const diagnosis = state.diagnosis || {};
    const proposal = diagnosis.proposal || {};
    const keywords = proposal.candidate_keywords || diagnosis.candidate_keywords || [];
    const issues = Array.isArray(diagnosis.issues) ? diagnosis.issues.filter(issue => typeof issue?.message === 'string') : [];
    const blocked = state.blocked_reason || (typeof proposal.blocked_reason === 'string' ? proposal.blocked_reason : null);
    const holdText = blocked || state.mutation_allowed === false && ['approval_required', 'needs_review', 'low_reach'].includes(status)
        ? holdReason(blocked) || 'Automatic changes are on hold. Review the diagnosis and proposed next steps before making changes.' : null;
    const blockedBy = Array.isArray(proposal.blocked_by)
        ? [...new Set(proposal.blocked_by.filter(reason => typeof reason === 'string').map(reviewReason))].filter(reason => reason !== holdText) : [];
    const actions = Array.isArray(proposal.suggested_actions) ? proposal.suggested_actions.filter(action => typeof action === 'string') : [];
    const isHealthy = ['delivering', 'recovered'].includes(status);
    const needsAttention = ['low_reach', 'approval_required', 'needs_review', 'unavailable'].includes(status);
    const styles = isHealthy ? 'border-green-200 bg-green-50' : needsAttention ? 'border-amber-200 bg-amber-50' : 'border-blue-200 bg-blue-50';
    const [label, explanation] = statuses[status];
    const forecast = diagnosis.forecast;
    const hasForecast = [forecast, diagnosis.combined_forecast].some(value => value && typeof value === 'object' && Object.keys(value).length > 0);
    const configuredHours = observedNumber(state.stale_after_hours);
    const stale = state.stale === true || !validTime(state.checked_at)
        || now - Date.parse(state.checked_at) > (configuredHours > 0 ? configuredHours : 3) * 3_600_000;

    return <section aria-label="Google Search delivery diagnosis" className={`rounded-xl border p-5 ${styles}`}>
        <div className="flex flex-wrap items-start justify-between gap-3">
            <h2 className="text-lg font-semibold text-gray-900">Google Search delivery</h2>
            <span className="rounded-full bg-white px-3 py-1 text-sm font-semibold text-gray-900">{label}</span>
        </div>
        <p className="mt-2 text-sm text-gray-800">{explanation}</p>
        {stale && <p className="mt-2 text-sm text-amber-900">This delivery check is out of date. A new check is needed before the campaign can be confirmed healthy.</p>}

        <div className="mt-4 rounded-lg border border-gray-200 bg-white p-4">
            <h3 className="text-sm font-semibold text-gray-900">Measured results</h3>
            <p className="mt-1 text-xs text-gray-600">From <Time value={measurement.from} /> through <Time value={measurement.through} />.</p>
            {observedNumber(measurement.complete_hours) !== null && <p className="mt-1 text-xs text-gray-600">{measuredCount(measurement.complete_hours)} complete reporting hours. Recent Google figures can still change.</p>}
            <dl className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[['Impressions', measuredCount(measurement.impressions)], ['Clicks', measuredCount(measurement.clicks)],
                    ['Ad spend', measuredSpend(measurement.cost_micros, state.currency_code)], ['Recorded conversions', measuredCount(measurement.conversions)]].map(([name, value]) => <div key={name}>
                    <dt className="text-xs text-gray-600">{name}</dt><dd className="mt-1 font-semibold text-gray-900">{value}</dd>
                </div>)}
            </dl>
        </div>

        {issues.length > 0 && <div className="mt-4"><h3 className="text-sm font-semibold text-gray-900">What limits delivery</h3>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-800">{issues.map((issue, index) => <li key={`${issue.code}-${index}`}>{issue.message}</li>)}</ul>
        </div>}
        {(proposal.summary || keywords.length > 0 || actions.length > 0) && <div className="mt-4">
            <h3 className="text-sm font-semibold text-gray-900">Proposed next steps</h3>
            {typeof proposal.summary === 'string' && <p className="mt-1 text-sm text-gray-800">{proposal.summary}</p>}
            {Array.isArray(keywords) && keywords.length > 0 && <><p className="mt-2 text-xs text-gray-600">Keywords suggested for review. A suggestion is not an applied change.</p>
                <ul className="mt-2 flex flex-wrap gap-2" aria-label="Proposed keywords">{keywords.filter(keyword => typeof keyword?.text === 'string').map((keyword, index) => <li key={`${keyword.text}-${keyword.match_type}-${index}`} className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800">
                    {keyword.text}<span className="ml-2 text-xs text-gray-600">{({ EXACT: 'Exact match', PHRASE: 'Phrase match', BROAD: 'Broad match' })[keyword.match_type] || 'Match type not confirmed'}</span>
                </li>)}</ul></>}
            {actions.length > 0 && <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-800" aria-label="Recommended next steps">{actions.map((action, index) => <li key={index}>{action}</li>)}</ul>}
        </div>}
        {hasForecast && <div className="mt-4 text-sm text-gray-700">
            <h3 className="font-semibold text-gray-900">Forecast evidence</h3>
            <ForecastEvidence forecast={forecast} label="Current keywords" currency={state.currency_code} />
            <ForecastEvidence forecast={diagnosis.combined_forecast} label="With proposed keywords" currency={state.currency_code} />
            <p className="mt-1 text-xs">Forecasts help assess possible changes. They are estimates, separate from the measured results above.</p>
        </div>}
        {blockedBy.length > 0 && <div className="mt-4"><h3 className="text-sm font-semibold text-gray-900">What needs review before changes</h3>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-800">{blockedBy.map((reason, index) => <li key={index}>{reason}</li>)}</ul>
        </div>}
        {holdText && <p className="mt-4 text-sm text-gray-800">{holdText}</p>}
        {['verifying', 'recovered'].includes(status) && <div className="mt-4 text-sm text-gray-700">
            <h3 className="font-semibold text-gray-900">After the repair</h3>
            {state.verification && <p className="mt-1">{measuredCount(state.verification.complete_hours)} complete reporting hours · {measuredCount(state.verification.impressions ?? state.verification.measurement?.impressions)} observed impressions.</p>}
            <p className="mt-1 text-xs">Applying changes alone does not prove recovery. Google must report traffic after the repair.</p>
        </div>}
        <p className="mt-4 text-xs text-gray-600">Last delivery check: <Time value={state.checked_at} /></p>
        {(state.measurement_started_at || state.evaluation_started_at) && <p className="mt-1 text-xs text-gray-600">Evaluation started: <Time value={state.measurement_started_at || state.evaluation_started_at} /></p>}
        {(refreshError || stale) && campaign.uuid && <button type="button" onClick={() => setRefreshAttempt(attempt => attempt + 1)} className="mt-3 inline-flex min-h-[44px] items-center text-sm font-semibold text-brand-darker underline">Refresh delivery status</button>}
    </section>;
}
