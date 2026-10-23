import React from 'react';
import { act, fireEvent, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { post, refreshPost, polling, pollResponse } = vi.hoisted(() => ({
    post: vi.fn(), refreshPost: vi.fn(), polling: vi.fn(), pollResponse: { data: null, error: null },
}));
vi.mock('@inertiajs/react', () => ({
    router: { post: refreshPost },
    useForm: (initial) => {
        const [data, update] = React.useState(initial);
        return { data, setData: (key, value) => update(previous => ({ ...previous, [key]: value })),
            post: (url, options) => post(url, options, data), processing: false, errors: {} };
    },
}));
vi.mock('@/hooks/usePolling', () => ({ usePolling: (...args) => { polling(...args); return pollResponse; } }));
vi.mock('@/Components/ForecastPanel', () => ({ default: () => { throw new Error('Illustrative forecasts cannot approve a budget.'); } }));

import MarketResearchPanel, { latestMarketResearch, researchCanBeApproved } from '@/Components/MarketResearchPanel';
import BudgetConfirmation from '@/Components/BudgetConfirmation';

const campaign = { id: 7, uuid: 'campaign-seven', daily_budget: '5.00', budget_confirmed_at: null };
const ready = (overrides = {}) => ({
    request_id: 'request-one', input_fingerprint: 'context-one', status: 'ready', current: true,
    approvable: true, approved: false, requested_at: '2026-10-23T01:00:00Z', checked_at: '2026-10-23T01:01:00Z',
    expires_at: '2099-10-23T01:16:00Z',
    result: { status: 'ready', fingerprint: 'context-one', proposed_daily_budget_micros: 20_000_000,
        strategies: [{ strategy_id: 11, platform: 'Google Ads', channel: 'search', status: 'ready',
            current_budget_micros: 5_000_000, current_cpc_bid_micros: 10_000,
            budget_micros: 20_000_000, cpc_bid_micros: 2_000_000, bidding_strategy: 'MANUAL_CPC', currency_code: 'AUD', time_zone: 'Australia/Sydney',
            current_keywords: [{ text: 'old selected phrase', match_type: 'PHRASE' }],
            proposed_keywords: [{ text: 'new relevant phrase', match_type: 'EXACT', selection_reason: 'Matches the reviewed offer.' }],
            forecast: { success: true, clicks: 31.25, cost_micros: 60_000_000, period_days: 30,
                forecast_period: { from: '2026-10-24', through: '2026-11-22' } },
            caveats: ['Negative keywords cannot be modeled by this forecast.'], reasons: [],
        }], reasons: [] }, ...overrides,
});
const budget = (research, props = {}) => <BudgetConfirmation campaign={campaign} currency="AUD" marketResearch={research} {...props} />;
const withOptions = () => {
    const research = ready();
    research.result.proposed_daily_budget_micros = 5_000_000;
    research.result.budget_options = [
        [5, 53.26, 145_000_000], [10, 97.9, 266_230_000], [15, 99.66, 269_290_000],
    ].map(([daily, estimate, cost], index) => {
        const proposal = JSON.parse(JSON.stringify(research.result));
        delete proposal.budget_options;
        proposal.proposed_daily_budget_micros = daily * 1_000_000;
        const forecast = { success: true, clicks: estimate, cost_micros: cost, period_days: 29,
            forecast_period: { from: '2026-10-25', through: '2026-11-22' } };
        Object.assign(proposal.strategies[0], {
            budget_micros: daily * 1_000_000, cpc_bid_micros: 5_000_000, forecast, forecast_period: forecast.forecast_period,
        });
        return { id: 'abc'[index].repeat(64), daily_budget_micros: daily * 1_000_000, cpc_bid_micros: 5_000_000,
            currency_code: 'AUD', time_zone: 'Australia/Sydney', forecast, proposal };
    });
    research.result.strategies = research.result.budget_options[0].proposal.strategies;
    research.result.default_budget_option_id = research.result.budget_options[0].id;
    research.result.selected_budget_option_id = research.result.default_budget_option_id;
    return research;
};

beforeEach(() => {
    post.mockReset(); refreshPost.mockReset(); polling.mockReset();
    pollResponse.data = null; pollResponse.error = null;
});
afterEach(() => vi.useRealTimers());

describe('campaign market research', () => {
    it('shows automatic research in progress and cannot approve the unchanged saved amount', () => {
        const research = ready({ status: 'researching', approvable: false, result: null });
        const view = render(budget(research, { marketResearchStatusUrl: '/market-research-status' }));
        expect(view.getByText('Researching your market')).toBeInTheDocument();
        expect(view.getByRole('status')).toHaveTextContent('before asking you to approve');
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(view.getByLabelText('Daily budget (AUD)')).toHaveValue(5);
        expect(polling).toHaveBeenCalledWith('/market-research-status', expect.objectContaining({ enabled: true, restartKey: 'request-one' }));
        expect(post).not.toHaveBeenCalled();
    });

    it('shows exact current/proposed context and period totals before explicit selection and approval', () => {
        const view = render(budget(ready()));
        expect(view.getByText('old selected phrase')).toBeInTheDocument();
        expect(view.getByText('new relevant phrase')).toBeInTheDocument();
        expect(view.getByText('CPC limit: AUD 0.01')).toBeInTheDocument();
        expect(view.getByText('CPC limit: AUD 2.00')).toBeInTheDocument();
        expect(view.getByText('Bidding: Manual cost-per-click bidding')).toBeInTheDocument();
        expect(view.getByText('31.25')).toBeInTheDocument();
        expect(view.getByText('AUD 60.00')).toBeInTheDocument();
        expect(view.getByText(/2026-10-24 to 2026-11-22.*30 days.*Australia\/Sydney/)).toBeInTheDocument();
        expect(view.getByText('Negative keywords cannot be modeled by this forecast.')).toBeInTheDocument();
        expect(view.getByLabelText('Daily budget (AUD)')).toHaveValue(5);
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();

        fireEvent.click(view.getByRole('button', { name: 'Use researched budget' }));
        expect(view.getByLabelText('Daily budget (AUD)')).toHaveValue(20);
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(view.getByRole('button', { name: 'Approve researched plan' }));
        expect(post).toHaveBeenCalledWith(expect.stringContaining('campaigns.confirm-budget'), expect.any(Object),
            { daily_budget: '20.00', research_id: 'request-one', research_fingerprint: 'context-one', budget_option_id: null, accept_proposal: true });
    });

    it('does not linearly scale traffic or approve an arbitrary edited amount', () => {
        const view = render(budget(ready()));
        fireEvent.click(view.getByRole('button', { name: 'Use researched budget' }));
        fireEvent.change(view.getByLabelText('Daily budget (AUD)'), { target: { value: '100' } });
        expect(view.getByText('31.25')).toBeInTheDocument();
        expect(view.getByText('AUD 60.00')).toBeInTheDocument();
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(refreshPost).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
    });

    it.each(['needs_review', 'unknown', 'stale'])('holds approval for %s research', (status) => {
        const view = render(budget(ready({ status, approvable: false })));
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(view.queryByRole('button', { name: 'Use researched budget' })).toBeNull();
        expect(post).not.toHaveBeenCalled();
    });

    it.each([
        ['expired', { expires_at: '2000-01-01T00:00:00Z' }],
        ['changed context', { current: false }],
        ['server refusal', { approvable: false }],
        ['unverified fingerprint', { input_fingerprint: 'different' }],
        ['already accepted', { approved: true, approvable: false }],
    ])('refuses a quote with %s', (name, changes) => {
        const view = render(budget(ready(changes)));
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(view.queryByRole('button', { name: 'Use researched budget' })).toBeNull();
    });

    it('expires a selected quote while the form remains open', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-23T01:01:00Z'));
        const view = render(budget(ready({ expires_at: '2026-10-23T01:01:01Z' })));
        fireEvent.click(view.getByRole('button', { name: 'Use researched budget' }));
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeEnabled();
        act(() => vi.advanceTimersByTime(1010));
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(view.getByText('This research needs refreshing')).toBeInTheDocument();
    });

    it('accepts polling completion for the same research request', () => {
        pollResponse.data = { marketResearch: ready() };
        const view = render(budget(ready({ status: 'queued', result: null, checked_at: null })));
        expect(view.getByText('Your researched campaign plan')).toBeInTheDocument();
        fireEvent.click(view.getByRole('button', { name: 'Use researched budget' }));
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeEnabled();
    });

    it('ignores an old successful poll when new props contain a different request', () => {
        pollResponse.data = { marketResearch: ready() };
        const research = ready({ request_id: 'new-request', status: 'queued', result: null, checked_at: null });
        const view = render(budget(research));
        expect(view.getByText('Researching your market')).toBeInTheDocument();
        expect(view.queryByText('new relevant phrase')).toBeNull();
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
    });

    it('does not let an older same-request success override a newer needs-review result', () => {
        const initial = ready({ status: 'needs_review', approvable: false, checked_at: '2026-10-23T01:02:00Z' });
        expect(latestMarketResearch(initial, ready())).toBe(initial);
        expect(researchCanBeApproved(initial)).toBe(false);
    });

    it('requires explicit selection again when a retried request has new input evidence at the same amount', () => {
        const view = render(budget(ready()));
        fireEvent.click(view.getByRole('button', { name: 'Use researched budget' }));
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeEnabled();
        const replacement = ready({ input_fingerprint: 'replacement-context' });
        replacement.result.fingerprint = 'replacement-context';
        replacement.result.strategies[0].proposed_keywords = [{ text: 'replacement relevant phrase', match_type: 'PHRASE' }];
        view.rerender(budget(replacement));
        expect(view.getByText('replacement relevant phrase')).toBeInTheDocument();
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });

    it('requests a retry through Inertia without changing spend or confirming a budget', () => {
        const view = render(budget(ready({ status: 'unknown', result: null, approvable: false }), { marketResearchRefreshUrl: '/retry-research' }));
        fireEvent.click(view.getByRole('button', { name: 'Retry research' }));
        expect(refreshPost).toHaveBeenCalledWith('/retry-research', {}, expect.objectContaining({ preserveScroll: true }));
        expect(view.getByLabelText('Daily budget (AUD)')).toHaveValue(5);
        expect(post).not.toHaveBeenCalled();
        act(() => refreshPost.mock.calls[0][2].onError());
        expect(view.getByRole('alert')).toHaveTextContent('your budget has not changed');
    });

    it('researches a custom draft amount without approving or changing saved spend', () => {
        const view = render(budget(ready(), { marketResearchRefreshUrl: '/research-custom-budget' }));
        fireEvent.change(view.getByLabelText('Daily budget (AUD)'), { target: { value: '75.50' } });
        expect(refreshPost).not.toHaveBeenCalled();
        fireEvent.click(view.getByRole('button', { name: 'Research this budget' }));
        expect(refreshPost).toHaveBeenCalledWith('/research-custom-budget', { daily_budget: '75.50' }, expect.any(Object));
        expect(post).not.toHaveBeenCalled();
        expect(view.getByText(/Saved daily budget:/)).toHaveTextContent('AUD 5.00');
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
    });

    it('does not describe existing managed campaigns as not live', () => {
        const view = render(budget(ready(), { campaign: { ...campaign, status: 'active', budget_confirmed_at: '2026-10-23' }, editing: true }));
        expect(view.queryByText(/Your ads are not live yet/)).toBeNull();
        expect(view.getByText(/Your current campaign stays as it is/)).toBeInTheDocument();
    });

    it('keeps an absent proposed bidding mode unverified', () => {
        const research = ready();
        delete research.result.strategies[0].bidding_strategy;
        const view = render(<MarketResearchPanel research={research} currency="AUD" />);
        expect(view.queryByText('Bidding: Manual cost-per-click bidding')).toBeNull();
        expect(view.getAllByText('Bidding: Not verified')).toHaveLength(2);
    });

    it('shows preserved existing allocations without presenting a new bid or traffic proposal', () => {
        const research = ready();
        research.result.strategies[0] = { strategy_id: 12, platform: 'Google Ads', channel: 'search', status: 'preserved',
            current_budget_micros: 5_000_000, budget_micros: 5_000_000, currency_code: 'AUD',
            current_keywords: [{ text: 'existing reviewed phrase', match_type: 'PHRASE' }] };
        const view = render(<MarketResearchPanel research={research} currency="AUD" />);
        expect(view.getByText('Existing campaign — allocation retained')).toBeInTheDocument();
        expect(view.getByText('Daily budget: AUD 5.00')).toBeInTheDocument();
        expect(view.getByText('existing reviewed phrase')).toBeInTheDocument();
        expect(view.queryByText('Proposed plan')).toBeNull();
        expect(view.queryByText(/CPC limit:/)).toBeNull();
        expect(view.queryByText('Traffic estimate')).toBeNull();
        expect(view.queryByText('Not verified')).toBeNull();
    });

    it('does not replace missing provider metrics with zero or invented conversions', () => {
        const research = ready();
        research.result.strategies[0].forecast = { success: false, clicks: null, cost_micros: null };
        const view = render(<MarketResearchPanel research={research} currency="AUD" />);
        expect(view.getAllByText('Not verified').length).toBeGreaterThanOrEqual(2);
        expect(view.queryByText('Conversions')).toBeNull();
    });

    it('distinguishes a viable recommendation for an existing campaign from failed traffic research', () => {
        const research = ready({ status: 'needs_review', approvable: false });
        research.result.status = 'needs_review';
        Object.assign(research.result.strategies[0], {
            status: 'needs_review', proposal_status: 'ready', requires_live_review: true,
        });
        const view = render(budget(research));
        expect(view.getByText('A researched recommendation needs review')).toBeInTheDocument();
        expect(view.getByText(/found a recommendation with useful forecast traffic/)).toBeInTheDocument();
        expect(view.queryByText(/have not found a plan/)).toBeNull();
        expect(view.getByText('31.25')).toBeInTheDocument();
        expect(view.getByLabelText('Daily budget (AUD)')).toHaveValue(5);
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(view.queryByRole('button', { name: 'Use researched budget' })).toBeNull();
        expect(post).not.toHaveBeenCalled();
    });

    it('retains the traffic warning when an existing campaign has no viable recommendation', () => {
        const research = ready({ status: 'needs_review', approvable: false });
        research.result.status = 'needs_review';
        Object.assign(research.result.strategies[0], {
            status: 'needs_review', proposal_status: 'needs_review', requires_live_review: true,
        });
        const view = render(<MarketResearchPanel research={research} currency="AUD" />);
        expect(view.getByText('The campaign plan needs review')).toBeInTheDocument();
        expect(view.getByText(/have not found a plan with enough verified traffic/)).toBeInTheDocument();
        expect(view.queryByText('A researched recommendation needs review')).toBeNull();
    });

    it('lets the customer compare exact forecast options and approve only the explicitly selected proposal', () => {
        const view = render(budget(withOptions()));
        expect(view.getAllByRole('radio')).toHaveLength(3);
        expect(view.getByText('About 53 clicks')).toBeInTheDocument();
        expect(view.getByText('About 98 clicks')).toBeInTheDocument();
        expect(view.getByText('About 100 clicks')).toBeInTheDocument();
        expect(view.getByText('AUD 266.23 estimated ad spend')).toBeInTheDocument();
        expect(view.getByText('Little extra traffic for the higher budget')).toBeInTheDocument();
        expect(view.getAllByRole('radio').every(radio => !radio.checked)).toBe(true);
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();

        fireEvent.click(view.getByRole('radio', { name: 'AUD 10.00 per day' }));
        expect(view.getByLabelText('Daily budget (AUD)')).toHaveValue(10);
        expect(view.getByText(/Saved daily budget:/)).toHaveTextContent('AUD 5.00');
        expect(view.getByText('97.9')).toBeInTheDocument();
        expect(view.getByText('CPC limit: AUD 5.00')).toBeInTheDocument();
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeEnabled();
        expect(post).not.toHaveBeenCalled();
        expect(refreshPost).not.toHaveBeenCalled();
        fireEvent.click(view.getByRole('button', { name: 'Approve researched plan' }));
        expect(post).toHaveBeenCalledWith(expect.stringContaining('campaigns.confirm-budget'), expect.any(Object), {
            daily_budget: '10.00', research_id: 'request-one', research_fingerprint: 'context-one',
            budget_option_id: 'b'.repeat(64), accept_proposal: true,
        });
    });

    it('requires another explicit choice when the options belong to a new research request', () => {
        const view = render(budget(withOptions()));
        fireEvent.click(view.getByRole('radio', { name: 'AUD 10.00 per day' }));
        const replacement = withOptions();
        replacement.request_id = 'new-request';
        view.rerender(budget(replacement));
        expect(view.getAllByRole('radio').every(radio => !radio.checked)).toBe(true);
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });

    it('holds mismatched or missing option forecasts and does not infer traffic from a budget multiplier', () => {
        const research = withOptions();
        research.result.budget_options[1].proposal.fingerprint = 'different-inputs';
        research.result.budget_options[2].forecast = null;
        const view = render(budget(research));
        expect(view.queryByText('About 98 clicks')).toBeNull();
        expect(view.queryByText('About 100 clicks')).toBeNull();
        expect(view.queryByRole('radio')).toBeNull();
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });
});
