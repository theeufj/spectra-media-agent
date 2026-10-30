import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    Head: () => null,
    usePage: () => ({ props: {}, url: '/' }),
    useForm: (initial = {}) => ({ data: initial, setData: vi.fn(), post: vi.fn(), processing: false, errors: {}, reset: vi.fn() }),
    router: { post: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('@/Components/CampaignCopilot', () => ({ default: () => null }));
vi.mock('@/Components/SetupJourney', () => ({ SetupStages: () => null, HandoverChecklist: () => null }));
vi.mock('@/hooks/useJobWatch', () => ({ useJobWatch: () => ({ phase: 'idle', data: null }) }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => ({ data: null, error: null }) }));

import PolicyStatusCard from '@/Components/PolicyStatusCard';
import CampaignShow from '@/Pages/Campaigns/Show';
import SetupHome from '@/Pages/Setup/Index';

const issue = {
    platform: 'google_ads', ad_resource_name: 'customers/123/adGroupAds/456~789', destination_issue: true,
    final_urls: ['https://business.example/services'],
    policy_topics: [{ topic: 'DESTINATION_NOT_WORKING', evidences: [{ type: 'destination_not_working', expanded_url: 'https://business.example/services', device: 'DESKTOP', http_error_code: 522, last_checked_at: '2026-10-03T00:20:00Z' }] }],
};
const snapshot = { status: 'issues', checked_at: '2026-10-03T00:21:00Z', last_successful_checked_at: '2026-10-03T00:21:00Z', repair_status: 'needs_website_repair', issues: [issue] };
const campaign = { id: 12, uuid: 'campaign-policy', name: 'Spring services', status: 'paused', google_ads_campaign_id: '123456', strategies: [], policy_checks: snapshot };

describe('Ad policy visibility', () => {
    it('shows Google destination evidence on a paused setup campaign before review or budget steps', () => {
        render(<CampaignShow auth={{ user: {} }} campaign={campaign} setupOnly />);
        const card = screen.getByRole('region', { name: 'Ad policy status for Spring services' });
        expect(within(card).getByText('HTTP 522')).toBeInTheDocument();
        expect(within(card).getByText('desktop')).toBeInTheDocument();
        expect(within(card).getByText(/Campaign status: Paused/)).toBeInTheDocument();
        expect(within(card).getByRole('link', { name: /https:\/\/business.example\/services/ })).toHaveAttribute('href', 'https://business.example/services');
        expect(within(card).getByText(/Changing ad copy cannot repair/)).toBeInTheDocument();
        expect(within(card).getByText(/Last policy check:/)).toBeInTheDocument();
        expect(within(card).getByText(/Google destination check/)).toBeInTheDocument();
        expect(within(card).queryByText(/team is working|healthy|operational/i)).not.toBeInTheDocument();
    });

    it('keeps the last rejection visible when a later policy check is unavailable', () => {
        render(<PolicyStatusCard campaign={campaign} policyStatus={{ ...snapshot, status: 'unknown', issues: [], last_incident: { issues: [issue] } }} />);
        expect(screen.getByText('Latest check unavailable')).toBeInTheDocument();
        expect(screen.getByText('HTTP 522')).toBeInTheDocument();
        expect(screen.getByText(/unavailable check does not mean the ads are approved/)).toBeInTheDocument();
        expect(screen.getByText(/Last successful policy check:/)).toBeInTheDocument();
        expect(screen.queryByText('No current policy issues reported')).not.toBeInTheDocument();
    });

    it('shows policy issues in the one-time setup dashboard after account handover', () => {
        const journey = { current_step: { key: 'handover', title: 'Your account is ready', description: 'Review your handover.' }, steps: [{ key: 'handover' }], business: { name: 'Business' }, campaign: { review_url: '/review' }, paid: true, checklist: [] };
        render(<SetupHome journey={journey} policyCampaigns={[campaign]} />);
        expect(screen.getByText('HTTP 522')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View campaign' })).toBeInTheDocument();
        expect(screen.getByText('Your account is ready')).toBeInTheDocument();
    });

    it('provides the account-control action as the primary handover step', () => {
        const journey = { current_step: { key: 'handover', title: 'Take control of your account', description: 'Confirm your Google login.',
            action_url: '/customers/customer-one/gtm/setup', action_text: 'Review account access and tracking' },
        steps: [{ key: 'handover' }], business: { name: 'Business' }, campaign: { review_url: '/review' }, paid: true, checklist: [] };
        render(<SetupHome journey={journey} />);
        expect(screen.getByRole('link', { name: 'Review account access and tracking' })).toHaveAttribute('href', '/customers/customer-one/gtm/setup');
        expect(screen.getByRole('link', { name: 'View your ads' })).toHaveAttribute('href', '/review');
    });

    it('distinguishes a successful clear check from a campaign being able to deliver', () => {
        render(<PolicyStatusCard campaign={campaign} policyStatus={{ status: 'clear', checked_at: snapshot.checked_at, issues: [] }} />);
        expect(screen.getByText('No current policy issues reported')).toBeInTheDocument();
        expect(screen.getByText(/Campaign delivery, billing and pause status are separate/)).toBeInTheDocument();
        expect(screen.getByText(/Campaign status: Paused/)).toBeInTheDocument();
    });

    it('keeps an initial unconfirmed check compact on the campaign and preserves admin visibility', () => {
        const { rerender } = render(<PolicyStatusCard campaign={{ ...campaign, policy_checks: null }} />);
        expect(screen.getByRole('status')).toHaveTextContent('Ad approval has not been confirmed yet.');
        expect(screen.queryByText('Unknown')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Get help with this issue' })).not.toBeInTheDocument();
        rerender(<PolicyStatusCard campaign={{ ...campaign, policy_checks: null }} admin />);
        expect(screen.getByText('Unknown')).toBeInTheDocument();
        expect(screen.getByText('No completed policy check is available yet.')).toBeInTheDocument();
    });

    it.each([
        null,
        { status: 'unknown', issues: [] },
        { status: 'unknown', issues: [], platforms: { google_ads: { error: 'The ads are awaiting review.' } } },
        { status: 'unknown', issues: [], last_incident: { issues: [issue], resolved_at: snapshot.checked_at } },
    ])('does not render a customer overview warning without an unresolved issue: %j', policyStatus => {
        const { container } = render(<PolicyStatusCard campaign={campaign} policyStatus={policyStatus} showClear={false} showCampaignLink />);
        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByText('Unknown')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Get help with this issue' })).not.toBeInTheDocument();
    });

    it('keeps confirmed limited approval visible on a paused campaign with a clear action', () => {
        const limited = { platform: 'google_ads', limited_approval: true, approval_status: 'APPROVED_LIMITED',
            message: 'Google approved this ad with restrictions.', policy_topics: [{ topic: 'TRADEMARKS' }] };
        render(<PolicyStatusCard campaign={campaign} policyStatus={{ ...snapshot, repair_status: 'needs_policy_review', issues: [limited] }} showClear={false} showCampaignLink />);
        expect(screen.getByText('Limited approval')).toBeInTheDocument();
        expect(screen.getByText('Ad delivery has restrictions')).toBeInTheDocument();
        expect(screen.getByText(/Google approved this ad with restrictions. Review the policy details/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Review in Google Ads' })).toHaveAttribute('href', 'https://ads.google.com/');
        expect(screen.getByText(/Campaign status: Paused/)).toBeInTheDocument();
        expect(screen.queryByText('No current policy issues reported')).not.toBeInTheDocument();
    });

    it('does not put an initial policy warning on the setup dashboard', () => {
        const journey = { current_step: { key: 'handover', title: 'Your account is ready', description: 'Review your handover.' }, steps: [{ key: 'handover' }], business: { name: 'Business' }, campaign: { review_url: '/review' }, paid: true, checklist: [] };
        render(<SetupHome journey={journey} policyCampaigns={[{ ...campaign, policy_checks: null }]} />);
        expect(screen.queryByRole('region', { name: /Ad policy status/ })).not.toBeInTheDocument();
        expect(screen.queryByText('Unknown')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Get help with this issue' })).not.toBeInTheDocument();
    });

    it('never creates an unsafe destination link', () => {
        const { rerender } = render(<PolicyStatusCard campaign={campaign} admin />);
        rerender(<PolicyStatusCard campaign={campaign} policyStatus={{ ...snapshot, issues: [{ ...issue, final_urls: ['javascript:alert(1)'], policy_topics: [] }] }} admin />);
        expect(screen.getByText('javascript:alert(1)')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'javascript:alert(1)' })).not.toBeInTheDocument();
        expect(screen.getByText(issue.ad_resource_name)).toBeInTheDocument();
    });
});
