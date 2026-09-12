import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    // The report reads the active customer's currency from shared props now,
    // rather than formatting every figure as en-US/USD.
    usePage: () => ({ props: { auth: { user: { active_customer: { currency_code: 'AUD' } } } } }),
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));

import AnalyticsAttribution from '@/Pages/Analytics/Attribution';
import CampaignAttribution from '@/Pages/Campaigns/Attribution';

/**
 * The two pages were ~340 byte-identical lines and now share
 * Components/AttributionReport. What each page still owns is its own header
 * and its own empty-state copy — the campaign one carries the pixel snippet,
 * the account-wide one has no single customer id to put in it.
 */
const shared = {
    summary: { total_conversions: 3, total_value: 1200, avg_touchpoints: 2.5 },
    channelBreakdown: {
        last_click: [{ channel: 'google / cpc', conversions: 3, value: 1200 }],
        first_click: [],
        linear: [],
        time_decay: [],
        position_based: [{ channel: 'google / cpc', conversions: 3, value: 1200 }],
    },
    recentTouchpoints: [],
    conversions: [],
};

const empty = { ...shared, summary: { total_conversions: 0, total_value: 0, avg_touchpoints: 0 } };

describe('attribution pages', () => {
    it('renders the shared report under the analytics header', () => {
        const { getByText } = render(<AnalyticsAttribution {...shared} />);

        // The back link used to say Analytics and point at analytics.index,
        // which redirects to the dashboard. Both now say dashboard.
        const back = getByText('← Back to dashboard');
        expect(back).toBeInTheDocument();
        expect(back.getAttribute('href')).toContain('dashboard');
        expect(getByText('Total Conversions')).toBeInTheDocument();
        expect(getByText('Channel Attribution by Model')).toBeInTheDocument();
        expect(getByText('Side-by-Side Model Comparison')).toBeInTheDocument();
    });

    it('renders the shared report under the campaign header', () => {
        const { getByText } = render(
            <CampaignAttribution
                {...shared}
                campaign={{ id: 7, name: 'Spring Sale' }}
                pixelConfig={{ customer_id: 42, signing_secret: 'sekret' }}
            />
        );

        expect(getByText('Back to Campaign')).toBeInTheDocument();
        expect(getByText('Total Conversions')).toBeInTheDocument();
        expect(getByText('Channel Attribution by Model')).toBeInTheDocument();
    });

    it('keeps the pixel snippet on the campaign empty state', () => {
        const { getByText, container } = render(
            <CampaignAttribution
                {...empty}
                campaign={{ id: 7, name: 'Spring Sale' }}
                pixelConfig={{ customer_id: 42, signing_secret: 'sekret' }}
            />
        );

        expect(getByText('No attribution data yet')).toBeInTheDocument();
        expect(container.textContent).toContain('data-customer="42"');
    });

    it('leaves it off the account-wide empty state, which has no single customer id', () => {
        const { getByText, container } = render(<AnalyticsAttribution {...empty} />);

        expect(getByText('No attribution data yet')).toBeInTheDocument();
        expect(container.textContent).not.toContain('spectra-pixel.js');
    });
});
