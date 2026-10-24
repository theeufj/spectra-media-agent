import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, fireEvent } from '@testing-library/react';

const postMock = vi.fn();
const pollingMock = vi.hoisted(() => vi.fn(() => ({ data: null, error: null })));

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
    useForm: (initial) => {
        const [data, setDataState] = React.useState(initial);

        return {
            data,
            setData: (key, value) => setDataState((d) => ({ ...d, [key]: value })),
            post: postMock,
            processing: false,
            errors: {},
        };
    },
}));
vi.mock('@/hooks/usePolling', () => ({ usePolling: (...args) => pollingMock(...args) }));

import BudgetConfirmationComponent from '@/Components/BudgetConfirmation';

function BudgetConfirmation(props) {
    const marketResearch = { request_id: 'fresh-research', input_fingerprint: 'context', status: 'ready',
        current: true, approvable: true, approved: false, expires_at: '2099-01-01T00:00:00Z',
        result: { status: 'ready', fingerprint: 'context', proposed_daily_budget_micros: Number(props.campaign.daily_budget) * 1_000_000, strategies: [] } };
    return <BudgetConfirmationComponent marketResearch={marketResearch} {...props} />;
}

describe('BudgetConfirmation', () => {
    beforeEach(() => {
        postMock.mockReset();
        pollingMock.mockReset();
        pollingMock.mockReturnValue({ data: null, error: null });
    });

    it.each(['not_requested', 'ready'])('fetches authoritative research for a newly watched strategy with raw %s metadata', status => {
        const raw = { request_id: 'new-research', input_fingerprint: 'context', status,
            result: { status: 'ready', fingerprint: 'context', proposed_daily_budget_micros: 25_000_000, strategies: [] } };
        const current = { ...raw, request_id: 'authoritative-new-request', status: 'ready', current: true, approvable: true, expires_at: '2099-01-01T00:00:00Z' };
        pollingMock.mockImplementation(url => ({ data: url === '/research/status' ? { marketResearch: current } : null, error: null }));
        const view = render(<BudgetConfirmationComponent campaign={{ uuid: 'new-campaign', daily_budget: '50.00' }}
            marketResearch={raw} marketResearchStatusUrl="/research/status" currency="AUD" />);

        const [, options] = pollingMock.mock.calls.find(([url]) => url === '/research/status');
        expect(options.enabled).toBe(true);
        expect(options.until({ marketResearch: { status: 'not_requested' } })).toBe(false);
        expect(options.until({ marketResearch: { status: 'queued' } })).toBe(false);
        expect(options.until({ marketResearch: { status: 'stale', current: false } })).toBe(false);
        expect(options.until({ marketResearch: { status: 'needs_review', current: false } })).toBe(false);
        expect(options.until({ marketResearch: { status: 'unknown', current: false } })).toBe(true);
        expect(options.until({ marketResearch: current })).toBe(true);
        expect(view.getByText('Your researched campaign plan')).toBeVisible();
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeDisabled();
        fireEvent.click(view.getByRole('button', { name: 'Use researched budget' }));
        expect(view.getByLabelText('Daily budget (AUD)')).toHaveValue(25);
        expect(view.getByRole('button', { name: 'Approve researched plan' })).toBeEnabled();
        expect(postMock).not.toHaveBeenCalled();
    });

    it('shows the confirmed state instead of the form once the budget is set', () => {
        const { getByText, queryByText } = render(
            <BudgetConfirmation campaign={{ id: 1, daily_budget: 45, budget_confirmed_at: '2026-08-29' }} currency="AUD" />
        );

        expect(getByText(/Budget confirmed/)).toBeInTheDocument();
        expect(queryByText('Approve researched plan')).toBeNull();
    });

    it('allows an existing budget to be edited or reconfirmed when explicitly requested', () => {
        const onConfirmed = vi.fn();
        const campaign = { id: 7, uuid: 'test-campaign', daily_budget: '5.00', budget_confirmed_at: '2026-10-23' };
        const { getByLabelText, getByText } = render(
            <BudgetConfirmation campaign={campaign} currency="AUD" editing onConfirmed={onConfirmed} />
        );

        expect(getByLabelText('Daily budget (AUD)')).toHaveValue(5);
        fireEvent.click(getByText('Use researched budget'));
        fireEvent.click(getByText('Approve researched plan'));
        expect(postMock).toHaveBeenCalledWith(
            route('campaigns.confirm-budget', { campaign: campaign.uuid }),
            expect.objectContaining({ onSuccess: expect.any(Function) })
        );
        expect(onConfirmed).not.toHaveBeenCalled();
        postMock.mock.calls[0][1].onSuccess();
        expect(onConfirmed).toHaveBeenCalledTimes(1);
    });

    it('states the seven-day upfront charge before the user agrees to it', () => {
        const { getByText } = render(
            <BudgetConfirmation
                campaign={{ id: 1, daily_budget: 45, budget_confirmed_at: null, budget_rationale: 'Enough clicks to learn.' }}
                currency="AUD"
            />
        );

        // 45 × 7 — the number that actually leaves their account.
        expect(getByText('AUD 315.00')).toBeInTheDocument();
    });

    it('recomputes the charge while edits remain unapproved until the researched amount is selected', () => {
        const { getByLabelText, getByText } = render(
            <BudgetConfirmation campaign={{ id: 7, daily_budget: 45, budget_confirmed_at: null }} currency="USD" />
        );

        fireEvent.change(getByLabelText(/Daily budget/), { target: { value: '60' } });
        expect(getByText('USD 420.00')).toBeInTheDocument();

        expect(getByText('Approve researched plan')).toBeDisabled();
        expect(postMock).not.toHaveBeenCalled();
        fireEvent.click(getByText('Use researched budget'));
        fireEvent.click(getByText('Approve researched plan'));
        expect(postMock).toHaveBeenCalledTimes(1);
        expect(postMock.mock.calls[0][0]).toContain('campaigns.confirm-budget');
    });

    it('disables confirmation while the budget field is empty', () => {
        const { getByLabelText, getByText } = render(
            <BudgetConfirmation campaign={{ id: 7, daily_budget: 45, budget_confirmed_at: null }} />
        );

        fireEvent.change(getByLabelText(/Daily budget/), { target: { value: '' } });
        expect(getByText('Approve researched plan')).toBeDisabled();
    });

    it('never promises a charge to a customer whose ads we do not fund', () => {
        /*
           Found on production, on the last screen before a US$999 customer
           creates their ads. It read "You'll be charged AUD 280.00 when you
           deploy — seven days up front. After that we top up as you spend."

           None of that happens to them. Their card is on their own Google Ads
           account, Google bills them directly, and DeployCampaign skips ad
           spend credit for a self-funded account entirely. It also contradicts
           what they bought: one payment, nothing recurring.
        */
        const { queryByText, getByText } = render(
            <BudgetConfirmation
                campaign={{ id: 1, daily_budget: 40, budget_confirmed_at: null }}
                currency="AUD"
                selfFunded
                setupOnly
            />
        );

        expect(queryByText(/You'll be charged/)).toBeNull();
        expect(queryByText(/seven days up front/)).toBeNull();
        expect(queryByText(/top up as you spend/)).toBeNull();
        expect(getByText(/the ad platform bills you directly/)).toBeInTheDocument();
        expect(getByText(/US\$999 was the whole engagement/)).toBeInTheDocument();
    });

    it('still states the charge for a customer we do fund', () => {
        // The self-funded branch must not swallow the disclosure for everyone
        // else — for a managed prepay account the charge is real and saying so
        // before they agree is the whole point of the panel.
        const { getByText } = render(
            <BudgetConfirmation
                campaign={{ id: 1, daily_budget: 40, budget_confirmed_at: null }}
                currency="AUD"
            />
        );

        expect(getByText(/seven days up front/)).toBeInTheDocument();
    });

    it('does not tell a setup-only customer their ads are about to go live', () => {
        // They arrive paused in the customer's own account; the customer
        // switches them on. "Before going live" is the managed promise.
        const { queryByText, getByText } = render(
            <BudgetConfirmation
                campaign={{ id: 1, daily_budget: 40, budget_confirmed_at: null }}
                currency="AUD"
                selfFunded
                setupOnly
            />
        );

        expect(queryByText(/before going live/)).toBeNull();
        expect(getByText(/Your ads arrive paused/)).toBeInTheDocument();
    });
});
