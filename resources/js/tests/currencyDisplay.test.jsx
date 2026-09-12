import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';

const page = vi.hoisted(() => ({ props: {} }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    router: { reload: vi.fn(), visit: vi.fn() },
}));

import PerformanceStats from '@/Components/PerformanceStats';
import { useCurrency } from '@/hooks/useCurrency';

/**
 * Money is labelled in the customer's own currency.
 *
 * Pages wrote `${value.toFixed(2)}` with a hardcoded dollar sign — 216 such
 * calls across 64 files. Nine of the seventeen production customers are on AUD,
 * so the majority were reading their own spend, budgets and forecasts marked as
 * US dollars on their own dashboard. The currency was on the wire the whole
 * time; nothing read it.
 */

function withCustomer(currency_code) {
    page.props = { auth: { user: { active_customer: currency_code ? { currency_code } : null } } };
}

function Probe() {
    return <span data-testid="currency">{useCurrency()}</span>;
}

describe('useCurrency', () => {
    it('reads the active customer\'s currency from shared props', () => {
        withCustomer('AUD');

        expect(render(<Probe />).getByTestId('currency').textContent).toBe('AUD');
    });

    it('falls back to USD, which is what the hardcoded signs assumed', () => {
        withCustomer(null);
        const first = render(<Probe />);
        expect(first.getByTestId('currency').textContent).toBe('USD');
        first.unmount();

        // No auth block at all — a page rendered before the customer resolves.
        page.props = {};
        expect(render(<Probe />).getByTestId('currency').textContent).toBe('USD');
    });
});

describe('AttributionReport', () => {
    const summary = { total_conversions: 12, total_value: 4500, avg_touchpoints: 3.2, avg_days_to_convert: 5 };

    it('does not format a customer\'s attributed value as US dollars', async () => {
        // formatCurrency() was hardcoded to en-US/USD, so every figure in the
        // report — total value, each channel's value, each conversion's value —
        // read as US dollars for every customer.
        const { default: AttributionReport } = await import('@/Components/AttributionReport');

        withCustomer('AUD');
        const aud = render(<AttributionReport summary={summary} channelBreakdown={{}} recentTouchpoints={[]} conversions={[]} />);
        const audText = aud.container.textContent;
        aud.unmount();

        withCustomer('USD');
        const usdText = render(<AttributionReport summary={summary} channelBreakdown={{}} recentTouchpoints={[]} conversions={[]} />).container.textContent;

        expect(audText).not.toBe(usdText);
    });
});

describe('PerformanceStats', () => {
    const stats = { total_spend: 1234, total_clicks: 5678, average_ctr: 2.4, average_cpa: 12.5 };

    it('does not label an AUD customer\'s spend as US dollars', () => {
        withCustomer('AUD');

        const { container } = render(<PerformanceStats stats={stats} />);
        const text = container.textContent;

        // Whatever the viewer's locale renders, AUD and USD must not format
        // identically — that difference is the entire point.
        withCustomer('USD');
        const usd = render(<PerformanceStats stats={stats} />).container.textContent;

        expect(text).not.toBe(usd);
    });

    it('formats counts and percentages without hand-rolled toFixed', () => {
        withCustomer('USD');

        const text = render(<PerformanceStats stats={stats} />).container.textContent;

        expect(text).toContain('5,678');
        expect(text).toContain('2.40%');
    });

    it('renders a zero state in the customer\'s currency too', () => {
        withCustomer('AUD');

        const zero = render(<PerformanceStats stats={null} />).container.textContent;

        withCustomer('USD');
        const zeroUsd = render(<PerformanceStats stats={null} />).container.textContent;

        // The empty state used to be a literal "$0" for everyone.
        expect(zero).not.toBe(zeroUsd);
    });
});
