import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, fireEvent, waitFor } from '@testing-library/react';

const toast = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { reload: vi.fn() },
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('@/Components/Toast', () => ({
    useToast: () => toast,
}));
vi.mock('@stripe/stripe-js', () => ({ loadStripe: () => null }));
vi.mock('@stripe/react-stripe-js', () => ({
    Elements: ({ children }) => <div>{children}</div>,
    CardElement: () => null,
    useStripe: () => null,
    useElements: () => null,
}));
vi.mock('@/Components/Modal', () => ({
    default: ({ children, show }) => (show ? <div>{children}</div> : null),
}));

import AdSpend from '@/Pages/Billing/AdSpend';
import AdSpendSetupModal from '@/Components/AdSpendSetupModal';

const auth = { user: { name: 'Test' } };

/**
 * The ledger printed a doubled minus.
 *
 * formatCurrency() already emits the sign, so the `type === 'deduction' ? '-'`
 * prefix rendered a $50 daily charge as "--$50.00" and a negative manual
 * adjustment as "+-$25.00". The stored sign is not consistent across types
 * either (deduct() writes negative, the legacy 'debit' rows are positive), so
 * the direction cannot be read off the type alone.
 */
describe('ad spend transaction history', () => {
    const renderLedger = (transactions) =>
        render(<AdSpend auth={auth} credit={null} transactions={transactions} paymentFailed={false} />);

    it('prints a single minus for a deduction', () => {
        const { getByText, queryByText } = renderLedger([
            { id: 1, type: 'deduction', amount: '-50.00', balance_after: '150.00', description: 'Daily ad spend charge', created_at: '2026-09-01T06:00:00Z' },
        ]);

        expect(getByText('-$50.00')).toBeInTheDocument();
        expect(queryByText('--$50.00')).toBeNull();
    });

    it('prints a plus for a credit', () => {
        const { getByText } = renderLedger([
            { id: 2, type: 'credit', amount: '100.00', balance_after: '250.00', description: 'Top-up', created_at: '2026-09-01T06:00:00Z' },
        ]);

        expect(getByText('+$100.00')).toBeInTheDocument();
    });

    it('prints a negative adjustment as a single minus, not "+-"', () => {
        const { getByText, queryByText } = renderLedger([
            { id: 3, type: 'adjustment', amount: '-25.00', balance_after: '125.00', description: 'Reconciliation', created_at: '2026-09-01T06:00:00Z' },
        ]);

        expect(getByText('-$25.00')).toBeInTheDocument();
        expect(queryByText('+-$25.00')).toBeNull();
    });

    it('prints the legacy positive-signed debit rows as money leaving', () => {
        const { getByText } = renderLedger([
            { id: 4, type: 'debit', amount: '12.34', balance_after: '87.66', description: 'Legacy debit', created_at: '2026-09-01T06:00:00Z' },
        ]);

        expect(getByText('-$12.34')).toBeInTheDocument();
    });

    it('colours a credit rather than leaving it the unknown-row grey', () => {
        const { getByText } = renderLedger([
            { id: 5, type: 'credit', amount: '100.00', balance_after: '250.00', description: 'Top-up', created_at: '2026-09-01T06:00:00Z' },
        ]);

        expect(getByText('credit').className).toContain('text-green-600');
    });
});

/**
 * The retry button posted to /billing/ad-spend/retry; the route is
 * /billing/ad-spend/retry-payment. Every retry 404'd, and the hand-rolled
 * response.json() turned the 404's HTML into a parse error, so a working card
 * and a declined one both read "An error occurred."
 */
describe('ad spend payment retry', () => {
    beforeEach(() => {
        toast.error.mockReset();
        global.fetch = vi.fn();
    });

    afterEach(() => {
        delete global.fetch;
    });

    it('posts to the named retry route with the CSRF header', async () => {
        global.fetch.mockResolvedValue({
            ok: true,
            status: 200,
            text: async () => JSON.stringify({ success: true }),
        });

        const { getByText } = render(
            <AdSpend auth={auth} credit={null} transactions={[]} paymentFailed={true} />
        );

        fireEvent.click(getByText('Retry Payment'));

        await waitFor(() => expect(global.fetch).toHaveBeenCalled());

        const [url, init] = global.fetch.mock.calls[0];
        expect(url).toBe('/__route__/billing.ad-spend.retry');
        expect(init.method).toBe('POST');
        expect(init.headers['X-CSRF-TOKEN']).toBeDefined();
    });

    it('shows the reason the server gave rather than a generic error', async () => {
        global.fetch.mockResolvedValue({
            ok: false,
            status: 400,
            text: async () => JSON.stringify({ success: false, error: 'Your card was declined.' }),
        });

        const { getByText } = render(
            <AdSpend auth={auth} credit={null} transactions={[]} paymentFailed={true} />
        );

        fireEvent.click(getByText('Retry Payment'));

        await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Your card was declined.'));
    });
});

/**
 * The deploy-funding modal hand-rolled the CSRF header and read the body with a
 * bare response.json(). The refusals a payer most needs to read — self-funded
 * account, unconfirmed budget, no card on file — all arrive as 4xx, and the
 * 419/HTML case made .json() throw, so every one of them showed as "An error
 * occurred. Please try again."
 */
describe('ad spend setup modal', () => {
    beforeEach(() => {
        global.fetch = vi.fn();
    });

    afterEach(() => {
        delete global.fetch;
    });

    const campaign = { id: 9, name: 'Spring Sale', total_budget: 900, start_date: '2026-09-01', end_date: '2026-09-30' };

    it('sends the CSRF header without reading the meta tag by hand', async () => {
        global.fetch.mockResolvedValue({
            ok: true,
            status: 200,
            text: async () => JSON.stringify({ success: true }),
        });

        const { getByText } = render(
            <AdSpendSetupModal
                show
                onClose={() => {}}
                onSuccess={() => {}}
                campaign={campaign}
                campaignName="Spring Sale"
                existingCredit={null}
                hasPaymentMethod
            />
        );

        fireEvent.click(getByText(/Deploy$/));

        await waitFor(() => expect(global.fetch).toHaveBeenCalled());

        const [url, init] = global.fetch.mock.calls[0];
        expect(url).toBe('/billing/ad-spend/setup-for-deployment');
        expect(init.headers['X-CSRF-TOKEN']).toBeDefined();
        expect(init.headers.Accept).toBe('application/json');
    });

    it('shows the server’s refusal instead of a generic error', async () => {
        global.fetch.mockResolvedValue({
            ok: false,
            status: 422,
            text: async () => JSON.stringify({
                success: false,
                error: 'Please confirm this campaign’s daily budget before funding it.',
            }),
        });

        const { getByText, findByText } = render(
            <AdSpendSetupModal
                show
                onClose={() => {}}
                onSuccess={() => {}}
                campaign={campaign}
                campaignName="Spring Sale"
                existingCredit={null}
                hasPaymentMethod
            />
        );

        fireEvent.click(getByText(/Deploy$/));

        expect(await findByText('Please confirm this campaign’s daily budget before funding it.')).toBeInTheDocument();
    });
});
