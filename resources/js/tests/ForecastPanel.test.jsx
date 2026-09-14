import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { frameForBudget } from '@/Components/ForecastPanel';
import ForecastPanel from '@/Components/ForecastPanel';

/**
 * The panel that tells a customer what their budget buys.
 *
 * Two things must hold however the figures move. Revenue is never invented —
 * without an order value the money lines are absent, not zero, because the same
 * figure goes on to drive budget reallocation. And the conversion rate is
 * always disclosed as ours: every other number on the panel is Google's
 * measurement of the customer's own market, and a forecast is not a promise.
 */

const RAW = {
    keywords: [
        { keyword: 'emergency plumber', monthly_searches: 2400, cpc: 4.1 },
        { keyword: 'blocked drain', monthly_searches: 800, cpc: 2.4 },
    ],
    max_cpc: 4.1,
    days: 30,
    conversion_rate: 0.03,
    budget: 1500,
    budget_capped: true,
    impressions: 40000,
    clicks: 1000,
    cost: 1500,
    conversions: 30,
    average_cpc: 1.5,
    ctr: 0.025,
    currency: 'AUD',
    country_known: true,
    has_order_value: false,
    unconstrained: { impressions: 80000, clicks: 2000, cost: 3000, conversions: 60 },
};

// fetchJson reads the body with response.text() and parses it itself, so a
// mock that only offers json() looks like an empty 200 and the panel renders
// nothing — which is exactly what it should do on a bad response, and would
// have made these assertions pass for the wrong reason.
function mockForecast(forecast) {
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            status: 200,
            text: () => Promise.resolve(JSON.stringify({ forecast })),
        })
    );
}

describe('frameForBudget', () => {
    it('scales the unconstrained totals down to the budget', () => {
        // Half the budget Google would happily spend, so half of everything.
        const framed = frameForBudget(RAW, 1500);

        expect(framed.cost).toBe(1500);
        expect(framed.clicks).toBe(1000);
        expect(framed.conversions).toBe(30);
        expect(framed.budget_capped).toBe(true);
    });

    it('does not inflate past what the market can absorb', () => {
        const framed = frameForBudget(RAW, 99999);

        expect(framed.cost).toBe(3000);
        expect(framed.clicks).toBe(2000);
        expect(framed.budget_capped).toBe(false);
    });

    it('recomputes revenue from the new conversion count', () => {
        const withValue = { ...RAW, has_order_value: true, order_value: 200 };

        // A quarter of the market: 15 conversions at 200 is 3000, against 750
        // of spend.
        const framed = frameForBudget(withValue, 750);

        expect(framed.conversions).toBe(15);
        expect(framed.revenue).toBe(3000);
        expect(framed.net).toBe(2250);
        expect(framed.roas).toBe(4);
    });

    it('leaves revenue alone when there is no order value', () => {
        const framed = frameForBudget(RAW, 750);

        expect(framed.revenue).toBeUndefined();
        expect(framed.roas).toBeUndefined();
        expect(framed.net).toBeUndefined();
    });

    it('survives a forecast with no unconstrained totals', () => {
        const { unconstrained, ...without } = RAW;

        expect(frameForBudget(without, 750)).toEqual(without);
    });

    it('is null-safe', () => {
        expect(frameForBudget(null, 1000)).toBeNull();
    });
});

describe('ForecastPanel', () => {
    beforeEach(() => vi.restoreAllMocks());
    afterEach(() => {
        delete globalThis.fetch;
    });

    it('asks for an order value instead of showing zero revenue', async () => {
        mockForecast(RAW);

        render(<ForecastPanel monthlyBudget={1500} />);

        await waitFor(() => expect(screen.getByText(/worth to you over their lifetime/i)).toBeInTheDocument());

        /*
           It asks for lifetime value, not one order. Asked "what's one
           customer worth?", the honest answer for a A$35/mo product is A$35 —
           wrong by an order of magnitude, and it forecast a 0.49x return where
           the same customer over a year returns 5.9x.
        */
        expect(screen.getByText(/not just their first order/i)).toBeInTheDocument();

        // The absence is still the point: no invented money figure anywhere.
        expect(screen.queryByText(/\$0\.00/)).not.toBeInTheDocument();
        expect(screen.getByText(/needs your order value/i)).toBeInTheDocument();
    });

    it('shows revenue and return once an order value exists', async () => {
        mockForecast({ ...RAW, has_order_value: true, order_value: 200, revenue: 6000, net: 4500, roas: 4 });

        render(<ForecastPanel monthlyBudget={1500} />);

        await waitFor(() => expect(screen.getByText(/4× return/)).toBeInTheDocument());
        expect(screen.queryByText(/one customer worth to you/i)).not.toBeInTheDocument();
    });

    it('always says the conversion rate is ours rather than Google\'s', async () => {
        mockForecast(RAW);

        render(<ForecastPanel monthlyBudget={1500} />);

        await waitFor(() =>
            expect(screen.getByText(/conversion rate is our estimate, not/i)).toBeInTheDocument()
        );
    });

    it('says so when it could not place the customer\'s country', async () => {
        mockForecast({ ...RAW, country_known: false });

        render(<ForecastPanel monthlyBudget={1500} />);

        await waitFor(() => expect(screen.getByText(/could not match your country/i)).toBeInTheDocument());
    });

    it('renders nothing rather than an error when there is no forecast', async () => {
        mockForecast(null);

        const { container } = render(<ForecastPanel monthlyBudget={1500} />);

        await waitFor(() => expect(container).toBeEmptyDOMElement());
    });

    it('renders nothing rather than breaking the page when the request fails', async () => {
        globalThis.fetch = vi.fn(() => Promise.reject(new Error('network')));

        const { container } = render(<ForecastPanel monthlyBudget={1500} />);

        await waitFor(() => expect(container).toBeEmptyDOMElement());
    });

    it('shows market size only in the market variant', async () => {
        mockForecast(RAW);

        render(<ForecastPanel variant="market" />);

        // 2400 + 800 searches across the two keywords.
        await waitFor(() => expect(screen.getByText('3,200')).toBeInTheDocument());
        expect(screen.queryByText(/one customer worth to you/i)).not.toBeInTheDocument();
    });
});

describe('the net line', () => {
    afterEach(() => { vi.restoreAllMocks(); });

    /*
       At a A$35 order value against A$8–A$40 clicks, a loss is exactly what an
       honest forecast shows. The panel rendered it in bg-green-50 regardless of
       sign, so "-A$689 left after ad spend" arrived in the success colour,
       directly above the button that confirms the budget. Showing it in green
       is the one way to make an honest number lie.
    */
    it('does not dress a loss up as a gain', async () => {
        mockForecast({ ...RAW, has_order_value: true, order_value: 35, revenue: 662, net: -689, roas: 0.49 });

        const { container } = render(<ForecastPanel monthlyBudget={1350} />);

        const line = await waitFor(() => {
            const el = container.querySelector('p.rounded-md');
            expect(el).toBeTruthy();

            return el;
        });

        expect(line.className).not.toContain('bg-green-50');
        expect(line.className).toContain('bg-amber-50');
    });

    it('still reads as good news when the forecast is profitable', async () => {
        mockForecast({ ...RAW, has_order_value: true, order_value: 400, revenue: 12000, net: 10500, roas: 8 });

        const { container } = render(<ForecastPanel monthlyBudget={1350} />);

        const line = await waitFor(() => {
            const el = container.querySelector('p.rounded-md');
            expect(el).toBeTruthy();

            return el;
        });

        expect(line.className).toContain('bg-green-50');
        expect(line.textContent).toContain('left after ad spend');
    });
});

