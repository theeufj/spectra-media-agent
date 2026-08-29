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
});
