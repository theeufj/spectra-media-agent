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
 * and its own empty-state copy. Neither page should ship a browser-visible
 * signing secret or imply this is the Google Ads conversion report.
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
        expect(getByText('Where conversions came from')).toBeInTheDocument();
        expect(getByText('Side-by-Side Model Comparison')).toBeInTheDocument();
    });

    it('renders the shared report under the campaign header', () => {
        const { getByText } = render(
            <CampaignAttribution
                {...shared}
                campaign={{ id: 7, name: 'Spring Sale' }}
            />
        );

        expect(getByText('Back to Campaign')).toBeInTheDocument();
        expect(getByText('Total Conversions')).toBeInTheDocument();
        expect(getByText('Where conversions came from')).toBeInTheDocument();
    });

    it('does not disclose a tracking signing secret on the campaign empty state', () => {
        const { getByText, container } = render(
            <CampaignAttribution
                {...empty}
                campaign={{ id: 7, name: 'Spring Sale' }}
            />
        );

        expect(getByText('No attribution data yet')).toBeInTheDocument();
        expect(container.textContent).not.toContain('data-secret');
        expect(container.textContent).toContain('Google Ads conversions are reported separately');
    });

    it('leaves it off the account-wide empty state, which has no single customer id', () => {
        const { getByText, container } = render(<AnalyticsAttribution {...empty} />);

        expect(getByText('No attribution data yet')).toBeInTheDocument();
        expect(container.textContent).not.toContain('spectra-pixel.js');
    });

    it('shows a usable public-site installation and receipt status without a secret', () => {
        const snippet = '<script src="https://sitetospend.com/js/spectra-pixel.js?v=123" data-site-id="123e4567-e89b-42d3-a456-426614174000" defer></script>';
        const { container } = render(
            <AnalyticsAttribution
                {...empty}
                trackingSetup={{ website_host: 'example.com', snippet, gtm_snippet: '<script>pixel.setAttribute("data-site-id", siteId)</script>', last_visit_at: null, last_conversion_at: null }}
            />
        );

        expect(container.textContent).toContain(snippet);
        expect(container.textContent).toContain('pixel.setAttribute');
        expect(container.textContent).toContain('Do not install another GTM container');
        expect(container.textContent).toContain('No events received yet');
        expect(container.textContent).toContain('trackConversion');
        expect(container.textContent).not.toContain('data-secret');
    });

    it('shows received visits without claiming there are no website events', () => {
        const { container } = render(
            <AnalyticsAttribution
                {...empty}
                recentTouchpoints={[{ id: 1, page_url: 'https://example.com/', touched_at: '2026-10-03T00:00:00Z' }]}
                trackingSetup={{ website_host: 'example.com', snippet: '<script></script>', gtm_snippet: '<script></script>', last_visit_at: '2026-10-03T00:00:00Z', last_conversion_at: null }}
            />
        );

        expect(container.textContent).toContain('Visits are arriving; no conversions yet');
        expect(container.textContent).toContain('Website visits are being recorded');
        expect(container.textContent).not.toContain('No website events have been recorded');
    });

    it('warns when Google Ads totals only contain older stored performance rows', () => {
        const { container } = render(
            <AnalyticsAttribution
                {...empty}
                googleSummary={{ conversions: 0, conversion_value: 0, latest_date: '2026-09-24' }}
            />
        );

        expect(container.textContent).toContain('Latest stored performance day');
        expect(container.textContent).toContain('incomplete as a current status check');
    });
});
