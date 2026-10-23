import { useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { brandTint } from '@/Components/Marketing/Hero';
import MarketResearchPanel, { isResearchPending, latestMarketResearch, researchBudgetOptions, researchCanBeApproved } from '@/Components/MarketResearchPanel';
import { usePolling } from '@/hooks/usePolling';

function budgetAmount(micros) {
    const cents = micros / 10_000;
    if (!Number.isSafeInteger(cents) || cents <= 0) return null;
    return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}

function budgetMicros(value) {
    const match = String(value).match(/^(\d+)(?:\.(\d{1,2}))?$/);
    return match ? Number(match[1]) * 1_000_000 + Number((match[2] || '').padEnd(2, '0')) * 10_000 : null;
}

export default function BudgetConfirmation({ campaign, currency = 'USD', selfFunded = false, setupOnly = false, editing = false, onConfirmed, marketResearch = null, marketResearchStatusUrl = null, marketResearchRefreshUrl = null }) {
    const { data, setData, post, processing, errors } = useForm({ daily_budget: campaign.daily_budget ?? '', research_id: '', research_fingerprint: '', budget_option_id: null, accept_proposal: false });
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState(null);
    const [now, setNow] = useState(Date.now());
    const initial = marketResearch || campaign.search_delivery_state?.market_research || null;
    const poll = usePolling(marketResearchStatusUrl, {
        interval: 5000, enabled: Boolean(marketResearchStatusUrl) && isResearchPending(initial),
        restartKey: initial?.request_id || campaign.uuid, until: (response) => !isResearchPending(response?.marketResearch),
    });
    const research = latestMarketResearch(initial, poll.data?.marketResearch);
    const options = researchBudgetOptions(research);
    const matchingRequest = data.research_id === research?.request_id && data.research_fingerprint === research?.input_fingerprint;
    const option = matchingRequest ? options.find(candidate => candidate.id === data.budget_option_id) : null;
    const proposal = option?.proposal || research?.result;
    const quote = budgetAmount(proposal?.proposed_daily_budget_micros);
    const fresh = researchCanBeApproved(research, now) && quote !== null;
    const selected = fresh && matchingRequest && data.accept_proposal === true
        && (options.length === 0 || Boolean(option))
        && budgetMicros(data.daily_budget) === proposal.proposed_daily_budget_micros;
    const displayedResearch = option ? { ...research, result: { ...proposal, budget_options: research.result.budget_options } } : research;
    const confirmed = Boolean(campaign.budget_confirmed_at);
    const weekly = Number(data.daily_budget || 0) * 7;

    useEffect(() => {
        setNow(Date.now());
        const expiry = Date.parse(research?.expires_at || '');
        if (!Number.isFinite(expiry) || expiry <= Date.now()) return undefined;
        const timer = setTimeout(() => setNow(Date.now()), Math.min(expiry - Date.now() + 5, 2_147_000_000));
        return () => clearTimeout(timer);
    }, [research?.request_id, research?.expires_at]);

    const refresh = (requestedBudget = null) => {
        setRefreshError(null);
        router.post(marketResearchRefreshUrl || route('campaigns.market-research.refresh', { campaign: campaign.uuid }), requestedBudget === null ? {} : { daily_budget: requestedBudget }, {
            preserveScroll: true, onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false),
            onError: () => setRefreshError('Research could not be requested. Try again; your budget has not changed.'),
        });
    };
    const useQuote = () => {
        if (!fresh) return;
        setData('daily_budget', quote);
        setData('research_id', research.request_id);
        setData('research_fingerprint', research.input_fingerprint);
        setData('budget_option_id', null);
        setData('accept_proposal', true);
    };
    const useOption = (optionId) => {
        if (!fresh) return;
        const chosen = options.find(candidate => candidate.id === optionId);
        if (!chosen) return;
        setData('daily_budget', budgetAmount(chosen.daily_budget_micros));
        setData('research_id', research.request_id);
        setData('research_fingerprint', research.input_fingerprint);
        setData('budget_option_id', chosen.id);
        setData('accept_proposal', true);
    };
    const submit = (event) => {
        event.preventDefault();
        if (!selected || processing || refreshing || refreshError || poll.error) return;
        post(route('campaigns.confirm-budget', { campaign: campaign.uuid }), {
            preserveScroll: true, ...(onConfirmed ? { onSuccess: onConfirmed } : {}),
        });
    };

    if (confirmed && !editing) {
        return <div className="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3"><p className="text-sm text-green-900"><span className="font-semibold">Budget confirmed</span> — {currency} {Number(campaign.daily_budget).toFixed(2)} a day. {setupOnly ? 'Your ads are built paused.' : 'Launch readiness is checked separately.'}</p></div>;
    }
    return <div className="mb-6 overflow-hidden rounded-lg border-2 bg-white shadow-sm" style={{ borderColor: brandTint(50) }}>
        <div className="border-b px-6 py-4" style={{ backgroundColor: brandTint(10), borderColor: brandTint(30) }}>
            <h3 className="text-base font-semibold text-gray-900">Review the researched plan and budget</h3>
            <p className="mt-1 text-sm text-gray-600">{setupOnly ? 'Your ads arrive paused. The agents research the market before proposing the keywords, bids and budget for your account.' : 'The agents research keyword demand and click costs before proposing changes. Your current campaign stays as it is until a reviewed update is applied.'}</p>
        </div>
        <div className="p-6">
            <MarketResearchPanel research={displayedResearch} currency={currency} onRefresh={() => refresh()} refreshing={refreshing} now={now}
                onSelectBudgetOption={fresh && options.length > 0 ? useOption : undefined} selectedBudgetOptionId={option?.id || null} selectingDisabled={processing || refreshing} />
            {(refreshError || poll.error) && <p role="alert" className="mt-3 text-sm text-red-700">{refreshError || 'The latest research status could not be checked. Refresh research before approving.'}</p>}
            <form onSubmit={submit} className="mt-5 border-t border-gray-200 pt-5">
                <p className="mb-3 text-sm text-gray-600">Saved daily budget: <strong>{currency} {Number(campaign.daily_budget || 0).toFixed(2)}</strong>. Your saved spend stays unchanged until you approve the researched plan.</p>
                {fresh && options.length === 0 && <div className="mb-4 flex flex-wrap items-center gap-3"><p className="text-sm text-gray-700">Researched daily budget: <strong>{currency} {quote}</strong></p><button type="button" onClick={useQuote} disabled={processing || refreshing} className="rounded-md border border-brand-dark px-3 py-2 text-sm font-medium text-brand-dark">Use researched budget</button></div>}
                <label htmlFor="daily_budget" className="block text-sm font-medium text-gray-700">Daily budget ({currency})</label>
                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <input id="daily_budget" type="number" min="5" step="0.01" value={data.daily_budget} onChange={(event) => setData('daily_budget', event.target.value)} aria-describedby="budget-research-approval" className="w-36 rounded-lg border-gray-300 text-sm focus:border-brand-primary focus:ring-brand-primary" />
                    <button type="button" onClick={() => refresh(data.daily_budget)} disabled={refreshing || processing || isResearchPending(research) || !(budgetMicros(data.daily_budget) >= 5_000_000)} className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 disabled:opacity-50">Research this budget</button>
                    <button type="submit" disabled={processing || !selected || refreshing || Boolean(refreshError) || Boolean(poll.error)} className="rounded-lg bg-brand-primary px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600">{processing ? 'Saving…' : 'Approve researched plan'}</button>
                </div>
                <p id="budget-research-approval" className="mt-2 text-sm text-gray-600">Approval accepts the proposed keyword set, bids and budget shown above. A different amount needs matching fresh research before it can be approved.</p>
                {[errors.daily_budget, errors.research_id, errors.research_fingerprint, errors.budget_option_id, errors.accept_proposal].filter(Boolean).map((error, index) => <p key={index} role="alert" className="mt-2 text-sm text-red-600">{error}</p>)}
                {selfFunded ? <p className="mt-3 text-sm text-gray-600">We won't charge you for this. The budget goes on your own ad platform account and the ad platform bills you directly{setupOnly ? ' — your US$999 was the whole engagement' : ''}. You can change or pause it at any time.</p>
                    : <p className="mt-3 text-sm text-gray-600">You'll be charged <strong>{currency} {weekly.toFixed(2)}</strong> when you deploy — seven days up front. After that we top up as you spend, and you can change or pause the budget at any time.</p>}
            </form>
        </div>
    </div>;
}
