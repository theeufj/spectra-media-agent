import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render } from '@testing-library/react';

const { page, postMock, visitMock, researchPostMock } = vi.hoisted(() => ({ page: { url: '/' }, postMock: vi.fn(), visitMock: vi.fn(), researchPostMock: vi.fn() }));
const watch = vi.hoisted(() => ({ phase: 'idle', data: null }));
const pollingRequests = vi.hoisted(() => vi.fn());
const researchStatus = vi.hoisted(() => ({ data: null }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    useForm: (initial) => {
        const [data, setData] = React.useState(initial);
        return { data, setData: (key, value) => setData(previous => ({ ...previous, [key]: value })), post: postMock, processing: false, errors: {} };
    },
    router: { post: researchPostMock, visit: visitMock },
    usePage: () => ({ props: {}, url: page.url }),
    Head: () => null,
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('@/Components/CampaignCopilot', () => ({ default: () => null }));
vi.mock('@/Components/ForecastPanel', () => ({ default: () => null }));
vi.mock('@/hooks/useJobWatch', () => ({ useJobWatch: () => watch }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: (...args) => { pollingRequests(...args); return args[0] === '/research/status' ? researchStatus : { data: null }; } }));

import CampaignShow from '@/Pages/Campaigns/Show';

beforeEach(() => {
    page.url = '/';
    postMock.mockReset();
    visitMock.mockReset();
    researchPostMock.mockReset();
    watch.phase = 'idle';
    watch.data = null;
    pollingRequests.mockClear();
    researchStatus.data = null;
});

const readyStrategies = [{ id: 11, uuid: 'draft-strategy', platform: 'Google Ads', campaign_type: 'search', ad_copy_strategy: 'Review this direction', imagery_strategy: 'None' }];

it('starts research automatically when strategy generation reveals the budget screen without a full page load', () => {
    const campaign = { uuid: 'strategy-arrives', name: 'New campaign', strategies: [], daily_budget: '40.00',
        currency_code: 'AUD', is_generating_strategies: true };
    const props = { auth: { user: {} }, campaign, marketResearchStatusUrl: '/research/status' };
    const view = render(<CampaignShow {...props} />);
    expect(researchPostMock).not.toHaveBeenCalled();
    expect(view.queryByLabelText('Daily budget (AUD)')).toBeNull();

    watch.phase = 'done';
    watch.data = { ...campaign, strategies: readyStrategies, is_generating_strategies: false };
    researchStatus.data = { marketResearch: { status: 'not_requested', current: false, approved: false } };
    view.rerender(<CampaignShow {...props} />);
    expect(view.getByLabelText('Daily budget (AUD)')).toHaveValue(40);
    expect(researchPostMock).toHaveBeenCalledExactlyOnceWith(route('campaigns.market-research.refresh', { campaign: campaign.uuid }),
        { automatic: true }, expect.objectContaining({ preserveScroll: true }));
    expect(postMock).not.toHaveBeenCalled();
});

it.each(['needs_review', 'stale'])('prepares the strategy before showing an old %s budget decision', status => {
    const campaign = { uuid: 'preparing-campaign', name: 'New campaign', strategies: [], daily_budget: '50.00',
        currency_code: 'AUD', auto_generated_at: '2026-10-24', is_generating_strategies: true };
    const { getByText, queryByRole, queryByText, queryByLabelText } = render(<CampaignShow auth={{ user: {} }} campaign={campaign}
        marketResearch={{ request_id: 'before-strategy', status, current: false, reasons: ['Old research should not be shown.'] }} />);

    expect(getByText('Preparing your campaign')).toBeVisible();
    expect(getByText(/Budget choices appear after your strategy is ready/)).toBeVisible();
    expect(queryByRole('heading', { name: 'Review the researched plan and budget' })).toBeNull();
    expect(queryByText('Old research should not be shown.')).toBeNull();
    expect(queryByText('The campaign plan needs review')).toBeNull();
    expect(queryByText('This research needs refreshing')).toBeNull();
    expect(queryByLabelText('Daily budget (AUD)')).toBeNull();
    expect(researchPostMock).not.toHaveBeenCalled();
    expect(postMock).not.toHaveBeenCalled();
});

it.each([null, { request_id: 'new-research', status: 'queued' }])('shows research after the watched strategy finishes with metadata %j and permits higher or lower amounts', researchAtCompletion => {
    const campaign = { uuid: 'strategy-arrives', name: 'New campaign', strategies: [], daily_budget: '50.00',
        currency_code: 'AUD', auto_generated_at: '2026-10-24', is_generating_strategies: true };
    const props = { auth: { user: {} }, campaign, marketResearch: { status: 'needs_review', current: false }, marketResearchStatusUrl: '/research/status' };
    const view = render(<CampaignShow {...props} />);
    expect(view.queryByLabelText('Daily budget (AUD)')).toBeNull();

    watch.phase = 'done';
    watch.data = { ...campaign, strategies: readyStrategies, is_generating_strategies: false,
        search_delivery_state: { market_research: researchAtCompletion } };
    view.rerender(<CampaignShow {...props} />);
    expect(view.getByText('Researching your market')).toBeVisible();
    expect(view.queryByText('The campaign plan needs review')).toBeNull();
    expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
    expect(pollingRequests).toHaveBeenCalledWith('/research/status', expect.objectContaining({ enabled: true }));
    const statusSettings = pollingRequests.mock.calls.find(([url]) => url === '/research/status')[1];
    expect(statusSettings.until({ marketResearch: { status: 'not_requested' } })).toBe(false);

    // Once the fresh research is available, choosing another amount researches
    // that amount without approving it or using the initial 50 as a ceiling.
    const ready = { ...watch.data, search_delivery_state: null };
    const research = { request_id: 'new-research', status: 'needs_review', current: true, approvable: false };
    view.rerender(<CampaignShow auth={{ user: {} }} campaign={ready} marketResearch={research} />);
    for (const amount of ['100', '25']) {
        fireEvent.change(view.getByLabelText('Daily budget (AUD)'), { target: { value: amount } });
        fireEvent.click(view.getByRole('button', { name: 'Research this budget' }));
        expect(researchPostMock).toHaveBeenLastCalledWith(route('campaigns.market-research.refresh', { campaign: campaign.uuid }),
            { daily_budget: amount }, expect.objectContaining({ preserveScroll: true }));
    }
    expect(researchPostMock).toHaveBeenCalledTimes(2);
    expect(campaign.daily_budget).toBe('50.00');
    expect(postMock).not.toHaveBeenCalled();
});

it('opens custom research from the saved campaign and researches AUD50 without changing the saved AUD5 or approving it', () => {
    const campaign = { id: 7, uuid: 'custom-research', name: 'Paused setup', strategies: readyStrategies,
        daily_budget: '5.00', approved_daily_budget: '5.00', budget_confirmed_at: '2026-10-23', currency_code: 'AUD' };
    const marketResearch = { request_id: 'existing-research', input_fingerprint: 'context', status: 'ready',
        current: true, approvable: true, expires_at: '2099-01-01T00:00:00Z',
        result: { status: 'ready', fingerprint: 'context', proposed_daily_budget_micros: 5_000_000, strategies: [] } };
    const props = { auth: { user: {} }, campaign, selfFunded: true, setupOnly: true, marketResearch };
    const { getByRole, getByText, getByLabelText, rerender } = render(<CampaignShow {...props} />);
    fireEvent.click(getByRole('button', { name: 'Review market research' }));
    const customLink = getByRole('link', { name: 'Research a custom amount' });
    expect(customLink).toHaveAttribute('href', `${route('campaigns.show', { campaign: campaign.uuid })}?budget=1#custom-budget-research`);
    page.url = customLink.getAttribute('href');
    rerender(<CampaignShow {...props} />);

    expect(getByRole('heading', { name: 'Research a custom daily budget' })).toBeVisible();
    expect(getByLabelText('Daily budget (AUD)').closest('form')).toHaveAttribute('id', 'custom-budget-research');
    expect(getByText(/AUD 50\/day/)).toHaveTextContent('Researching does not save your budget or activate ads.');
    fireEvent.change(getByLabelText('Daily budget (AUD)'), { target: { value: '50' } });
    expect(getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
    fireEvent.click(getByRole('button', { name: 'Research this budget' }));
    expect(researchPostMock).toHaveBeenCalledExactlyOnceWith(route('campaigns.market-research.refresh', { campaign: campaign.uuid }), { daily_budget: '50' }, expect.objectContaining({ preserveScroll: true }));
    expect(getByText(/Saved daily budget:/)).toHaveTextContent('Saved daily budget: AUD 5.00');
    expect(campaign.daily_budget).toBe('5.00');
    expect(campaign.approved_daily_budget).toBe('5.00');
    expect(postMock).not.toHaveBeenCalled();
    expect(visitMock).not.toHaveBeenCalled();
});

it.each(['', '4.99', '50.001', '10000.01', '100000', '1e10'])('does not request unbounded or invalid custom daily budget %s', value => {
    page.url = '/campaigns/bounded-custom?budget=1';
    const campaign = { id: 7, uuid: 'bounded-custom', name: 'Paused setup', strategies: readyStrategies, daily_budget: '5.00',
        budget_confirmed_at: '2026-10-23', currency_code: 'AUD' };
    const { getByLabelText, getByRole } = render(<CampaignShow auth={{ user: {} }} campaign={campaign} />);
    expect(getByLabelText('Daily budget (AUD)')).toHaveAttribute('max', '10000');
    fireEvent.change(getByLabelText('Daily budget (AUD)'), { target: { value } });
    expect(getByRole('button', { name: 'Research this budget' })).toBeDisabled();
    fireEvent.click(getByRole('button', { name: 'Research this budget' }));
    expect(researchPostMock).not.toHaveBeenCalled();
    expect(postMock).not.toHaveBeenCalled();
});

it('shows the current native purchase connection instead of asking for the legacy signup container', () => {
    const campaign = { id: 7, uuid: 'native-campaign', name: 'Native purchases', strategies: [],
        daily_budget: '5.00', budget_confirmed_at: '2026-10-23', currency_code: 'AUD' };
    const { getByText, queryByText, getByRole } = render(<CampaignShow auth={{ user: {} }} campaign={campaign}
        conversionTracking={{ container_id: 'GTM-OLD-SIGNUP', installed: false, native: { connected: true }, setup_url: '/campaigns/native-campaign/conversion-goal' }} />);
    expect(getByText('Paid checkout tracking is connected')).toBeVisible();
    expect(queryByText('GTM-OLD-SIGNUP')).toBeNull();
    expect(queryByText('Install your tracking snippet before launch')).toBeNull();
    expect(getByRole('link', { name: 'Review purchase tracking' })).toHaveAttribute('href', '/campaigns/native-campaign/conversion-goal');
});

it('opens the confirmed budget form from Change and returns to review after reconfirmation', () => {
    const campaign = {
        id: 7, uuid: 'test-campaign', name: 'Setup campaign', strategies: readyStrategies,
        auto_generated_at: '2026-10-23', budget_confirmed_at: '2026-10-23',
        daily_budget: '5.00', currency_code: 'AUD',
    };
    const marketResearch = { request_id: 'research-seven', input_fingerprint: 'context', status: 'ready',
        current: true, approvable: true, expires_at: '2099-01-01T00:00:00Z',
        result: { status: 'ready', fingerprint: 'context', proposed_daily_budget_micros: 5_000_000, strategies: [] } };
    const props = { auth: { user: {} }, campaign, setupOnly: true, selfFunded: true, marketResearch };
    const { getByRole, getByLabelText, queryByLabelText, rerender } = render(<CampaignShow {...props} />);
    expect(queryByLabelText('Daily budget (AUD)')).toBeNull();

    page.url = getByRole('link', { name: 'Change' }).getAttribute('href');
    rerender(<CampaignShow {...props} />);

    expect(getByLabelText('Daily budget (AUD)')).toHaveValue(5);
    const reviewUrl = route('campaigns.show', { campaign: campaign.uuid });
    expect(getByRole('link', { name: 'Cancel budget change' })).toHaveAttribute('href', reviewUrl);
    fireEvent.click(getByRole('button', { name: 'Use researched budget' }));
    fireEvent.click(getByRole('button', { name: 'Approve researched plan' }));
    expect(postMock).toHaveBeenCalledWith(
        route('campaigns.confirm-budget', { campaign: campaign.uuid }),
        expect.objectContaining({ onSuccess: expect.any(Function) })
    );
    expect(visitMock).not.toHaveBeenCalled();
    postMock.mock.calls[0][1].onSuccess();
    expect(visitMock).toHaveBeenCalledWith(reviewUrl, { preserveScroll: true });
});

it.each([
    ['manual managed', false, false], ['generated managed', true, false],
    ['manual setup', false, true], ['generated setup', true, true],
])('requires automatic research before approval for an unconfirmed %s campaign', (name, generated, setupOnly) => {
    const campaign = { id: 7, uuid: 'unconfirmed-campaign', name: 'New campaign', strategies: readyStrategies,
        daily_budget: '5.00', currency_code: 'AUD', budget_confirmed_at: null,
        auto_generated_at: generated ? '2026-10-23' : null };
    const { getByRole, getByText } = render(<CampaignShow auth={{ user: {} }} campaign={campaign} setupOnly={setupOnly}
        marketResearch={{ request_id: 'pending', status: 'researching', current: true, approvable: false }} />);
    expect(getByText('Researching your market')).toBeInTheDocument();
    expect(getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
    expect(postMock).not.toHaveBeenCalled();
});

it('keeps a manual draft direction reviewable while preventing creative approval before researched budget approval', () => {
    const campaign = { id: 7, uuid: 'manual-draft', name: 'Manual draft', daily_budget: '5.00', budget_confirmed_at: null,
        strategies: [{ id: 11, uuid: 'draft-strategy', platform: 'Google Ads', ad_copy_strategy: 'Review this direction', imagery_strategy: 'Photos' }] };
    const { getByText, getByRole } = render(<CampaignShow auth={{ user: {} }} campaign={campaign} />);
    expect(getByText('Review this direction')).toBeInTheDocument();
    expect(getByRole('button', { name: 'Approve direction & prepare ads' })).toBeDisabled();
    expect(postMock).not.toHaveBeenCalled();
});
