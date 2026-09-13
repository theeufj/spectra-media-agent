import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, fireEvent } from '@testing-library/react';

const postMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
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

import BudgetConfirmation from '@/Components/BudgetConfirmation';

describe('BudgetConfirmation', () => {
    beforeEach(() => postMock.mockReset());

    it('shows the confirmed state instead of the form once the budget is set', () => {
        const { getByText, queryByText } = render(
            <BudgetConfirmation campaign={{ id: 1, daily_budget: 45, budget_confirmed_at: '2026-08-29' }} currency="AUD" />
        );

        expect(getByText(/Budget confirmed/)).toBeInTheDocument();
        expect(queryByText('Confirm budget')).toBeNull();
    });

    it('states the seven-day upfront charge before the user agrees to it', () => {
        const { getByText } = render(
            <BudgetConfirmation
                campaign={{ id: 1, daily_budget: 45, budget_confirmed_at: null, budget_rationale: 'Enough clicks to learn.' }}
                currency="AUD"
            />
        );

        expect(getByText('Enough clicks to learn.')).toBeInTheDocument();
        // 45 × 7 — the number that actually leaves their account.
        expect(getByText('AUD 315.00')).toBeInTheDocument();
    });

    it('recomputes the upfront charge as the user edits, and submits the confirmation', () => {
        const { getByLabelText, getByText } = render(
            <BudgetConfirmation campaign={{ id: 7, daily_budget: 45, budget_confirmed_at: null }} currency="USD" />
        );

        fireEvent.change(getByLabelText(/Daily budget/), { target: { value: '60' } });
        expect(getByText('USD 420.00')).toBeInTheDocument();

        fireEvent.click(getByText('Confirm budget'));
        expect(postMock).toHaveBeenCalledTimes(1);
        expect(postMock.mock.calls[0][0]).toContain('campaigns.confirm-budget');
    });

    it('disables confirmation while the budget field is empty', () => {
        const { getByLabelText, getByText } = render(
            <BudgetConfirmation campaign={{ id: 7, daily_budget: 45, budget_confirmed_at: null }} />
        );

        fireEvent.change(getByLabelText(/Daily budget/), { target: { value: '' } });
        expect(getByText('Confirm budget')).toBeDisabled();
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
        expect(getByText(/Google bills you directly/)).toBeInTheDocument();
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
