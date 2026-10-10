const reasons = {
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

export default function CampaignSpendSafetyCard({ campaign }) {
    const trial = campaign?.spend_guardrails?.enabled === true ? campaign.spend_guardrails : null;
    const hold = campaign?.spend_safety_hold;
    if (!trial && !hold) return null;
    const currency = trial?.currency_code ?? hold?.snapshot?.currency_code;

    return (
        <section aria-labelledby="campaign-spend-protection" className={`rounded-xl border p-5 ${hold ? 'border-amber-300 bg-amber-50' : 'border-blue-200 bg-blue-50'}`}>
            <h2 id="campaign-spend-protection" className="text-lg font-semibold text-gray-900">{hold ? 'Spending paused for review' : 'Campaign test spending limits'}</h2>
            {hold && <div className="mt-2 text-sm text-gray-800">
                <p>{reasons[hold.reason] ?? 'Campaign spending needs review before restarting.'}</p>
                <p className="mt-1">{hold.verified_at ? 'Google confirmed that this campaign is paused.' : 'A pause has been requested. Google confirmation is still pending.'}</p>
                <p className="mt-1">Review the limits with your administrator before restarting. Automatic management will keep this spending hold in place.</p>
            </div>}
            {trial && <>
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
