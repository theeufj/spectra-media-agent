import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render } from '@testing-library/react';

const { page, postMock, visitMock } = vi.hoisted(() => ({ page: { url: '/' }, postMock: vi.fn(), visitMock: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    useForm: (initial) => {
        const [data, setData] = React.useState(initial);
        return { data, setData: (key, value) => setData(previous => ({ ...previous, [key]: value })), post: postMock, processing: false, errors: {} };
    },
    router: { post: vi.fn(), visit: visitMock },
    usePage: () => ({ props: {}, url: page.url }),
    Head: () => null,
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('@/Components/CampaignCopilot', () => ({ default: () => null }));
vi.mock('@/Components/ForecastPanel', () => ({ default: () => null }));
vi.mock('@/hooks/useJobWatch', () => ({ useJobWatch: () => ({ phase: 'idle', data: null }) }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => ({ data: null }) }));

import CampaignShow from '@/Pages/Campaigns/Show';

beforeEach(() => {
    page.url = '/';
    postMock.mockReset();
    visitMock.mockReset();
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
        id: 7, uuid: 'test-campaign', name: 'Setup campaign', strategies: [],
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
    const campaign = { id: 7, uuid: 'unconfirmed-campaign', name: 'New campaign', strategies: [],
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
