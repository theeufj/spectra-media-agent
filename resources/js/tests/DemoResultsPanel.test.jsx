import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...props }) => <a href={href} {...props}>{children}</a>,
}));

import DemoResultsPanel from '@/Components/DemoResultsPanel';

const result = {
    url: 'https://example.test',
    ad_copy: { headlines: ['A fixture offer'], descriptions: ['Fixture description'] },
    visuals: {},
    forecast: {
        budget_capped: true, budget: 150, max_cpc: 4, days: 30,
        clicks: 100, conversions: 3, cost: 150, conversion_rate: 0.03,
        keywords: [{ keyword: 'fixture keyword', monthly_searches: 0, cpc: 4 }],
    },
};

afterEach(cleanup);

describe('demo forecast availability', () => {
    it.each([null, undefined])('shows unknown impressions as unavailable rather than zero (%s)', impressions => {
        render(<DemoResultsPanel result={{ ...result, forecast: { ...result.forecast, impressions } }} />);

        const card = screen.getByText('Impressions').parentElement;
        expect(within(card).getByText('—')).toBeVisible();
        expect(within(card).queryByText('0')).toBeNull();
        expect(within(screen.getByText('Clicks').parentElement).getByText('100')).toBeVisible();
        expect(within(screen.getByText('Ad spend').parentElement).getByText('$150.00')).toBeVisible();
    });

    it('preserves explicit zero forecast metrics and keyword search volume', () => {
        render(<DemoResultsPanel result={{ ...result, forecast: { ...result.forecast, impressions: 0, clicks: 0, conversions: 0 } }} />);

        for (const metric of ['Impressions', 'Clicks', 'Conversions']) {
            expect(within(screen.getByText(metric).parentElement).getByText('0')).toBeVisible();
        }
        expect(within(screen.getByText('fixture keyword').closest('tr')).getByText('0')).toBeVisible();
    });
});
