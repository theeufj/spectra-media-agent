import { dateTime } from '@/utils/format';
import { usePolling } from '@/hooks/usePolling';

const reasons = {
    ai_max_reported_spend_review_reached: 'The AI Max pilot reached its spending review threshold.',
    ai_max_approved_end_reached: 'The AI Max pilot reached its approved end time.',
    ai_max_purchase_delivery_failed: 'A setup purchase could not be delivered to Google for optimization.',
    ai_max_configuration_unverified: 'The AI Max settings could not be verified against the approved pilot.',
    ai_max_serving_limits_changed: 'The AI Max campaign differs from its approved spending or bidding settings.',
    ai_max_approval_unverified: 'The AI Max pilot approval needs review.',
    ai_max_spend_evidence_unverified: 'Current AI Max spending could not be verified.',
    trial_spend_cap_reached: 'The test reached its spending review threshold.',
    trial_no_conversions_after_goal_spend: 'The test spent its review allowance without a recorded conversion.',
    no_conversions_after_matured_spend: 'The campaign spent its review allowance without recorded conversions, after allowing for reporting delays.',
    trial_daily_budget_exceeded: 'The daily budget exceeded the agreed test limit.',
    trial_bidding_limit_missing: 'The agreed maximum CPC bid was missing or exceeded.',
    trial_bid_modifier_exceeded: 'A bid adjustment could raise bids above the agreed test limit.',
    trial_campaign_type_changed: 'The campaign changed from the agreed Google Search test.',
    manual_pause: 'You paused this campaign.',
    manual_pause_drift: 'Google was still running a campaign that had been paused here.',
    approved_end_date_passed: 'The campaign reached its approved end date.',
};

function money(micros, currency) {
    const amount = Number(micros) / 1_000_000;
    if (!Number.isFinite(amount)) return 'Not set';
    if (!/^[A-Z]{3}$/.test(currency ?? '')) return `${amount.toFixed(2)} in ad account currency`;
    return new Intl.NumberFormat('en-AU', { style: 'currency', currency, currencyDisplay: 'code' }).format(amount);
}

export default function CampaignSpendSafetyCard({ campaign: initialCampaign }) {
    const native = initialCampaign?.spend_guardrails?.ai_max_pilot?.enabled === true || initialCampaign?.search_delivery_state?.ai_max_request?.enabled === true;
    const { data: refreshed, error } = usePolling(native && initialCampaign.uuid ? route('api.campaigns.show', { campaign: initialCampaign.uuid }) : null, {
        interval: 60_000,
        enabled: Boolean(native && initialCampaign.uuid),
        parse: value => {
            if (!value || value.uuid !== initialCampaign.uuid || !Object.hasOwn(value, 'spend_guardrails')) throw new Error('Pilot spending status could not be refreshed.');
            return value;
        },
    });
    const campaign = refreshed?.uuid && refreshed.uuid === initialCampaign?.uuid ? refreshed : initialCampaign;
    const trial = campaign?.spend_guardrails?.enabled === true ? campaign.spend_guardrails : null;
    const pilot = campaign?.spend_guardrails?.ai_max_pilot?.enabled === true ? campaign.spend_guardrails.ai_max_pilot : null;
    const request = campaign?.search_delivery_state?.ai_max_request?.enabled === true ? campaign.search_delivery_state.ai_max_request : null;
    const stoppedRequest = ['revoked', 'cancelled'].includes(request?.status);
    const hold = campaign?.spend_safety_hold;
    if (!trial && !pilot && !request && !hold) return null;
    const currency = pilot?.currency_code ?? trial?.currency_code ?? hold?.snapshot?.currency_code;
    const monitor = campaign?.search_delivery_state?.ai_max_monitor;
    const report = campaign?.ai_max_performance;
    const reported = monitor?.reported_spend_micros;
    const checked = typeof monitor?.checked_at === 'string' && Number.isFinite(Date.parse(monitor.checked_at)) ? monitor.checked_at : null;

    return (
        <section aria-labelledby="campaign-spend-protection" className={`rounded-xl border p-5 ${hold ? 'border-amber-300 bg-amber-50' : 'border-blue-200 bg-blue-50'}`}>
            <h2 id="campaign-spend-protection" className="text-lg font-semibold text-gray-900">{hold ? 'Spending paused for review' : pilot ? 'Google AI Max pilot' : stoppedRequest ? 'Google AI Max setup stopped' : request ? 'Preparing Google AI Max' : 'Campaign test spending limits'}</h2>
            {hold && <div className="mt-2 text-sm text-gray-800">
                <p>{reasons[hold.reason] ?? 'Campaign spending needs review before restarting.'}</p>
                <p className="mt-1">{hold.verified_at ? 'Google confirmed that this campaign is paused.' : 'A pause has been requested. Google confirmation is still pending.'}</p>
                <p className="mt-1">Review the limits with your administrator before restarting. Automatic management will keep this spending hold in place.</p>
            </div>}
            {request && !pilot && <div className="mt-2 text-sm text-gray-800">
                {stoppedRequest ? <p>The activation request was cancelled or the campaign changed. No automatic restart will be attempted.</p>
                    : request.status?.includes('review') ? <p>The setup needs an administrator review before it can continue. No automatic restart will be attempted.</p>
                    : <p>Activation is waiting for purchase-import validation and fresh campaign checks. Google can take several hours to recognize a new purchase action.</p>}
                <p className="mt-2">The current bidding limits remain in force until the approved pilot is configured. Existing campaign pauses and spending holds take priority.</p>
                {error && <p role="status" className="mt-2 text-amber-800">The latest setup status could not be refreshed.</p>}
            </div>}
            {pilot && <>
                <p className="mt-2 text-sm text-gray-800">Google manages conversion bidding and search matching when this pilot is active. The goal is completed setup purchases.</p>
                <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                    <div><dt className="text-sm text-gray-600">Average daily budget</dt><dd className="mt-1 font-semibold">{money(pilot.max_daily_budget_micros, currency)}</dd></div>
                    <div><dt className="text-sm text-gray-600">Pause for review after reported spend</dt><dd className="mt-1 font-semibold">{money(pilot.review_spend_micros, currency)}</dd></div>
                    <div><dt className="text-sm text-gray-600">Reported spend since pilot started</dt><dd className="mt-1 font-semibold">{reported != null ? money(reported, currency) : 'Awaiting Google report'}</dd></div>
                </dl>
                {typeof pilot.ends_at === 'string' && Number.isFinite(Date.parse(pilot.ends_at)) && <p className="mt-3 text-sm text-gray-700">Approved until <time dateTime={pilot.ends_at}>{dateTime(pilot.ends_at)}</time>.</p>}
                <p className="mt-2 text-sm text-gray-700">Conversion bidding has no per-click price cap. Google can spend up to twice the average daily budget on a day. Checks run every 15 minutes; reported spend can lag, so the review threshold is not a guaranteed billing cap.</p>
                <p className="mt-2 text-sm text-gray-700">Purchase delivery, targeting and spending checks continue while automated bid, keyword and creative repairs are held during this pilot.</p>
                {checked && <p className="mt-2 text-sm text-gray-600">Last spending check: <time dateTime={checked}>{dateTime(checked)}</time>. Configuration checks do not confirm sales.</p>}
                {error && <p role="status" className="mt-2 text-sm text-amber-800">The latest status could not be refreshed. These are the last recorded spending checks.</p>}
                {report && <div className="mt-4 border-t border-blue-200 pt-4">
                    <h3 className="font-semibold text-gray-900">Reported purchase results</h3>
                    {report.metrics && report.period?.through ? <>
                        <p className="mt-1 text-sm text-gray-600">Complete Google account days: {report.period.from} to {report.period.through}. Activation and current partial days are excluded.</p>
                        <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                            <div><dt className="text-sm text-gray-600">Attributed setup purchases</dt><dd className="font-semibold">{new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(report.metrics.conversions)}</dd></div>
                            <div><dt className="text-sm text-gray-600">Spend in this reporting period</dt><dd className="font-semibold">{money(report.metrics.cost_micros, report.currency_code)}</dd></div>
                            <div><dt className="text-sm text-gray-600">Cost per attributed purchase</dt><dd className="font-semibold">{report.metrics.cpa_micros != null ? money(report.metrics.cpa_micros, report.currency_code) : 'Not available yet'}</dd></div>
                        </dl>
                        {report.combinations?.rows?.length > 0 && <details className="mt-3 text-sm">
                            <summary className="cursor-pointer font-medium">Review native search terms and ads</summary>
                            <ul className="mt-2 space-y-3">{report.combinations.rows.map((row, index) => <li key={index} className="rounded border border-blue-100 bg-white p-3">
                                <p className="font-medium">{row.search_term}</p><p>{row.headline}</p>
                                {row.landing_page && <p className="break-all text-gray-600">{row.landing_page}</p>}
                            </li>)}</ul>
                            <p className="mt-2 text-gray-600">These combinations may not include every campaign click.</p>
                        </details>}
                    </> : <p className="mt-2 text-sm text-gray-700">{report.status === 'unavailable' ? 'Purchase reporting is temporarily unavailable; no zero result has been assumed.' : 'Waiting for the first complete reporting day after activation.'}</p>}
                    {report.status === 'partial' && <p className="mt-2 text-sm text-gray-700">Campaign totals are available; native search combinations could not be refreshed.</p>}
                    <p className="mt-2 text-sm text-gray-600">Google attribution can change as conversions arrive. These observations do not establish the uplift caused by AI Max.</p>
                </div>}
            </>}
            {trial && !pilot && <>
                <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                    {[
                        ['Stop for review after', trial.max_spend_micros],
                        ['Average daily budget limit', trial.max_daily_budget_micros],
                        ['Maximum CPC bid', trial.max_cpc_bid_micros],
                    ].map(([label, value]) => <div key={label}>
                        <dt className="text-sm text-gray-600">{label}</dt>
                        <dd className="mt-1 font-semibold text-gray-900">{money(value, currency)}</dd>
                    </div>)}
                </dl>
                <p className="mt-3 text-sm text-gray-700">The spending threshold counts this test from its starting balance. Checks run every 15 minutes; Google reporting can lag, so this is a stop threshold rather than a guaranteed billing cap.</p>
                <p className="mt-2 text-sm text-gray-700">Automatic campaign changes are suspended during the test so its agreed limits stay in place.</p>
            </>}
        </section>
    );
}
