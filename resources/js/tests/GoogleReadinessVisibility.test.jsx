import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import GoogleReadinessCard from '@/Components/GoogleReadinessCard';
import DeploymentStatus from '@/Pages/Campaigns/DeploymentStatus';
import CampaignDetail from '@/Pages/Admin/CampaignDetail';

const page = vi.hoisted(() => ({ props: { auth: { user: {} } } }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }) => <a href={href}>{children}</a>,
    usePage: () => page,
    useForm: (data = {}) => ({ data, errors: {}, setData: vi.fn(), put: vi.fn(), post: vi.fn(), processing: false }),
    router: { reload: vi.fn(), post: vi.fn() },
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ header, children }) => <>{header}{children}</> }));
vi.mock('@/Components/Toast', () => ({ useToast: () => ({ success: vi.fn() }) }));
vi.mock('@/Pages/Admin/SideNav', () => ({ default: () => null }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => ({ data: null, error: null, failureStreak: 0 }) }));

const healthy = { status: 'ready', ready: true, checked_at: '2026-10-05T00:00:00Z', issues: [], errors: [],
    conversion_goals: { status: 'ready', ready: true, intent: { category: 'SIGNUP' }, issues: [] },
    ad_strength: { checked: true, verified: true, unresolved: [], errors: [] } };
const pending = { ...healthy, status: 'needs_review', ready: false, issues: [{ code: 'ad_strength_google_review_or_strength_pending', message: 'Google has not confirmed the ad strength yet.' }],
    ad_strength: { checked: true, verified: false, unresolved: [{ reason: 'google_review_or_strength_pending' }] } };

beforeEach(() => {
    page.props = { auth: { user: {} } };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200, text: async () => '{}' }));
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

describe('Google readiness visibility', () => {
    it('shows pending Google review independently of the verified conversion goal', () => {
        render(<GoogleReadinessCard readiness={pending} />);
        const card = screen.getByRole('region', { name: 'Google campaign readiness' });
        expect(within(card).getAllByText('Awaiting Google review')).toHaveLength(2);
        expect(within(card).getByText('Conversion goal · Sign-up')).toBeVisible();
        expect(within(card).getByText('Verified')).toBeVisible();
        expect(within(card).queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('confirms readiness only after both applicable checks pass', () => {
        render(<GoogleReadinessCard readiness={healthy} />);
        expect(screen.getAllByText('Verified')).toHaveLength(3);
        expect(screen.getByText(/Delivery still depends on approval, billing and pause settings/)).toBeVisible();
        expect(screen.getByText(/Last readiness check:/)).toBeVisible();
    });

    it('retains last-known issues and timestamps while paused without a green readiness claim', () => {
        render(<GoogleReadinessCard readiness={{ ...pending, skip_reason: 'campaign_not_active', ad_strength: { checked: false, verified: false, skipped: 'campaign_not_active', last_known: pending.ad_strength, last_known_checked_at: healthy.checked_at } }} />);
        expect(screen.getByText(/Automatic repairs are on hold while the campaign is inactive or paused/)).toBeVisible();
        expect(screen.getByText('Google has not confirmed the ad strength yet.')).toBeVisible();
        expect(screen.getByText(/Last ad strength evidence:/)).toBeVisible();
        expect(screen.queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('prioritizes a newer failed conversion check over a previous green aggregate', () => {
        render(<GoogleReadinessCard readiness={healthy} conversionGoals={{ status: 'unknown', ready: false, checked_at: '2026-10-05T01:00:00Z', issues: [{ code: 'conversion_action_scope_unavailable', message: 'Conversion action access needs review.' }] }} />);
        expect(screen.getAllByText('Unknown')).toHaveLength(2);
        expect(screen.getByText('Conversion action access needs review.')).toBeVisible();
        expect(screen.getByText(/This does not confirm that the campaign is ready/)).toBeVisible();
        expect(screen.queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('does not show Deployment Complete while Google strength remains pending', () => {
        render(<DeploymentStatus campaign={{ id: 61, name: 'Signups' }} deployments={[{ id: 771, platform: 'Google Ads (SEM)', status: 'verified', progress: 4, google_readiness: pending }]} />);
        expect(screen.getByRole('region', { name: 'Google campaign readiness' })).toBeVisible();
        expect(screen.getByText(/Readiness checks pending/)).toBeVisible();
        expect(screen.queryByText(/Deployment Complete!/)).toBeNull();
        expect(screen.getByRole('button', { name: 'Refresh readiness status' })).toBeVisible();
    });

    it('shows a failed current goal check on an admin strategy before expansion', () => {
        page.props = { campaign: { id: 61, uuid: 'campaign-61', name: 'Signups', status: 'paused', daily_budget: 50, total_budget: 350, customer: { uuid: 'customer-1', name: 'Business', users: [] }, strategies: [{ id: 771, platform: 'Google Ads (SEM)', deployment_status: 'verified', google_ads_campaign_id: 'customers/123/campaigns/61', execution_result: { metadata: { google_readiness: healthy, conversion_goal_readiness: { status: 'needs_review', ready: false, issues: [{ code: 'conversion_access', message: 'Google conversion action access needs repair.' }] } } } }] } };
        render(<CampaignDetail auth={{ user: {} }} />);
        const card = screen.getByRole('region', { name: 'Google campaign readiness' });
        expect(within(card).getByText('Needs attention')).toBeVisible();
        expect(within(card).getByText('Google conversion action access needs repair.')).toBeVisible();
        expect(within(card).queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('shows a first-launch goal failure in admin before any Google campaign has been created', () => {
        page.props = { campaign: { id: 61, uuid: 'campaign-61', name: 'Signups', status: 'draft', customer: { uuid: 'customer-1', name: 'Business', users: [] }, strategies: [{ id: 771, platform: 'Google Ads (SEM)', deployment_status: 'failed', google_ads_campaign_id: null, execution_result: { metadata: { conversion_goal_readiness: { status: 'unknown', ready: false, issues: [{ code: 'conversion_access', message: 'Conversion action authorization is unavailable.' }] } } } }] } };
        render(<CampaignDetail auth={{ user: {} }} />);
        const card = screen.getByRole('region', { name: 'Google campaign readiness' });
        expect(within(card).getByText('Conversion action authorization is unavailable.')).toBeVisible();
        expect(within(card).getAllByText('Unknown')).toHaveLength(2);
        expect(within(card).queryByText(/last check confirmed the applicable/)).toBeNull();
    });
});
