import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render } from '@testing-library/react';

const { page, postMock, visitMock } = vi.hoisted(() => ({ page: { url: '/' }, postMock: vi.fn(), visitMock: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    useForm: (initial) => ({ data: initial, setData: vi.fn(), post: postMock, processing: false, errors: {} }),
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

it('opens the confirmed budget form from Change and returns to review after reconfirmation', () => {
    const campaign = {
        id: 7, uuid: 'test-campaign', name: 'Setup campaign', strategies: [],
        auto_generated_at: '2026-10-23', budget_confirmed_at: '2026-10-23',
        daily_budget: '5.00', currency_code: 'AUD',
    };
    const props = { auth: { user: {} }, campaign, setupOnly: true, selfFunded: true };
    const { getByRole, getByLabelText, queryByLabelText, rerender } = render(<CampaignShow {...props} />);
    expect(queryByLabelText('Daily budget (AUD)')).toBeNull();

    page.url = getByRole('link', { name: 'Change' }).getAttribute('href');
    rerender(<CampaignShow {...props} />);

    expect(getByLabelText('Daily budget (AUD)')).toHaveValue(5);
    const reviewUrl = route('campaigns.show', { campaign: campaign.uuid });
    expect(getByRole('link', { name: 'Cancel budget change' })).toHaveAttribute('href', reviewUrl);
    fireEvent.click(getByRole('button', { name: 'Confirm budget' }));
    expect(postMock).toHaveBeenCalledWith(
        route('campaigns.confirm-budget', { campaign: campaign.uuid }),
        expect.objectContaining({ onSuccess: expect.any(Function) })
    );
    expect(visitMock).not.toHaveBeenCalled();
    postMock.mock.calls[0][1].onSuccess();
    expect(visitMock).toHaveBeenCalledWith(reviewUrl, { preserveScroll: true });
});
