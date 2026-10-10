import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import GoogleReadinessCard, { googleReadinessStatus } from '@/Components/GoogleReadinessCard';
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
    ad_strength: { checked: true, verified: true, unresolved: [], errors: [] },
    audience_observation: { status: 'ready', ready: true, applicable: true, issues: [] } };
const pending = { ...healthy, status: 'pending', ready: false, pending: [{ code: 'ad_strength_strength_pending', message: 'Google has not confirmed the ad strength yet.' }],
    ad_strength: { checked: true, verified: false, pending_since: '2026-10-05T00:00:00Z', expires_at: '2026-10-08T00:00:00Z', unresolved: [{ reason: 'strength_pending', waiting: true }] } };

beforeEach(() => {
    page.props = { auth: { user: {} } };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200, text: async () => '{}' }));
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

describe('Google readiness visibility', () => {
    it('shows pending Google review independently of the verified conversion goal', () => {
        render(<GoogleReadinessCard readiness={pending} />);
        const card = screen.getByRole('region', { name: 'Google campaign readiness' });
        expect(within(card).getAllByText('Waiting for Google evaluation')).toHaveLength(2);
        expect(within(card).getByText('Conversion goal · Sign-up')).toBeVisible();
        expect(within(card).getAllByText('Verified')).toHaveLength(2);
        expect(within(card).getByText(/campaign is not fully verified yet/)).toBeVisible();
        expect(within(card).getByText(/Waiting since:/)).toBeVisible();
        expect(within(card).getByText(/Flag for review if still waiting at:/)).toBeVisible();
        expect(within(card).getByRole('list', { name: 'Pending Google evaluation' })).toHaveTextContent('Google has not confirmed the ad strength yet.');
        expect(within(card).queryByText('Needs attention')).toBeNull();
        expect(within(card).queryByRole('link', { name: 'Get help with readiness' })).toBeNull();
        expect(within(card).queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('shows a waiting observation under an approved trial without hiding the mutation hold', () => {
        render(<GoogleReadinessCard readiness={{ ...pending, skip_reason: 'approved_bounded_trial' }} />);
        expect(screen.getAllByText('Waiting for Google evaluation')).toHaveLength(2);
        expect(screen.getByText(/Automatic changes are on hold to preserve the approved trial; Google checks still run/)).toBeVisible();
    });

    it('flags a delayed Google evaluation as actionable rather than hiding its timeout as pending', () => {
        const issue = { code: 'ad_strength_google_evaluation_delayed', message: 'Google evaluation has remained pending beyond the observation window.' };
        render(<GoogleReadinessCard readiness={{ ...pending, status: 'needs_review', pending: [], issues: [issue],
            ad_strength: { checked: true, verified: false, unresolved: [{ reason: 'google_evaluation_delayed' }] } }} />);
        expect(screen.getByText('Needs attention')).toBeVisible();
        expect(screen.getByText(issue.message)).toBeVisible();
        expect(screen.getByRole('link', { name: 'Get help with readiness' })).toBeVisible();
        expect(screen.queryByText('Waiting for Google evaluation')).toBeNull();
        expect(screen.queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('does not hide a disapproval alongside a genuine pending observation', () => {
        const issue = { code: 'ad_strength_policy_disapproved', message: 'Google disapproved an ad for policy reasons.' };
        render(<GoogleReadinessCard readiness={{ ...pending, issues: [issue] }} />);
        expect(screen.getByText('Needs attention')).toBeVisible();
        expect(screen.getByText(issue.message)).toBeVisible();
        expect(screen.getByText('Waiting for Google evaluation')).toBeVisible();
        expect(screen.queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('never verifies an inconsistent aggregate while provider observations are pending', () => {
        expect(googleReadinessStatus({ ...healthy, pending: pending.pending })).toBe('pending');
    });

    it('keeps direct component read failures unknown even when the aggregate is pending', () => {
        const issue = { code: 'ad_strength_unavailable', message: 'Google ad strength is unavailable.' };
        render(<GoogleReadinessCard readiness={{ ...pending, ad_strength: { ...pending.ad_strength, errors: [issue] } }} />);
        expect(screen.getAllByText('Unknown')).toHaveLength(2);
        expect(screen.getByText(issue.message)).toBeVisible();
        expect(screen.queryByText('Waiting for Google evaluation')).toBeNull();
    });

    it('keeps newer conversion failures actionable during Google evaluation', () => {
        const issue = { code: 'conversion_action_scope_unavailable', message: 'Current Google conversion access needs repair.' };
        render(<GoogleReadinessCard readiness={pending} conversionGoals={{ status: 'unknown', ready: false, checked_at: '2026-10-05T01:00:00Z', issues: [issue] }} />);
        expect(screen.getAllByText('Unknown')).toHaveLength(2);
        expect(screen.getByText(issue.message)).toBeVisible();
        expect(screen.queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('accepts the specific legacy pending observation without treating arbitrary pending codes as waiting', () => {
        const legacyIssue = { code: 'ad_strength_google_review_or_strength_pending', message: 'Google evaluation is pending.' };
        const { pending: observations, ...legacy } = pending;
        expect(googleReadinessStatus({ ...legacy, status: 'needs_review', issues: [legacyIssue] })).toBe('pending');
        expect(googleReadinessStatus({ ...pending, issues: [{ code: 'ad_strength_verification_pending_timeout', message: 'Verification failed to complete.' }] })).toBe('needs_review');
    });

    it.each([
        ['ad_strength_verification_pending', 'verification_pending', { verification_status: 'needs_review', replacement_fingerprint: 'fingerprint-1' }, 'Replacement ad verification exhausted its recovery attempts.'],
        ['ad_strength_strength_pending', 'strength_pending', {}, 'The replacement ad fingerprint is unavailable, so its strength cannot be verified.'],
    ])('keeps modern repair failures actionable even with the pending-related code %s', (code, reason, details, message) => {
        render(<GoogleReadinessCard readiness={{ ...pending, status: 'needs_review', pending: [], issues: [{ code, message }],
            ad_strength: { checked: true, verified: false, unresolved: [{ reason, ...details }] } }} />);
        expect(screen.getByText('Needs attention')).toBeVisible();
        expect(screen.getByText(message)).toBeVisible();
        expect(screen.getByText('Needs review')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Get help with readiness' })).toBeVisible();
        expect(screen.queryByText('Waiting for Google evaluation')).toBeNull();
        expect(screen.queryByRole('list', { name: 'Pending Google evaluation' })).toBeNull();
        expect(screen.queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it.each(['ad_strength_review_pending', 'ad_strength_strength_pending', 'ad_strength_verification_pending'])('recognizes the confirmed provider observation %s', code => {
        render(<GoogleReadinessCard readiness={{ ...pending, pending: [{ code, message: 'Google is evaluating this ad.' }],
            ad_strength: { checked: true, verified: false, unresolved: [{ reason: 'provider_waiting', waiting: true }] } }} />);
        expect(screen.getAllByText('Waiting for Google evaluation')).toHaveLength(2);
        expect(screen.queryByText('Needs attention')).toBeNull();
    });

    it('keeps an unknown provider status actionable instead of treating it as normal evaluation', () => {
        const issue = { code: 'ad_strength_ad_status_unknown', message: 'Google did not provide a usable ad status.' };
        render(<GoogleReadinessCard readiness={{ ...pending, status: 'needs_review', pending: [], issues: [issue],
            ad_strength: { checked: true, verified: false, unresolved: [{ reason: 'ad_status_unknown' }] } }} />);
        expect(screen.getByText('Needs attention')).toBeVisible();
        expect(screen.getByText(issue.message)).toBeVisible();
        expect(screen.queryByText('Waiting for Google evaluation')).toBeNull();
    });

    it('confirms readiness only after every applicable check passes', () => {
        render(<GoogleReadinessCard readiness={healthy} />);
        expect(screen.getAllByText('Verified')).toHaveLength(4);
        expect(screen.getByText(/Delivery still depends on approval, billing and pause settings/)).toBeVisible();
        expect(screen.getByText(/Last readiness check:/)).toBeVisible();
    });

    it('keeps audience restrictions visible even if an aggregate says ready', () => {
        const issue = { code: 'search_audience_observation_required', message: 'Audience signals are restricting Search reach.' };
        render(<GoogleReadinessCard readiness={{ ...healthy, audience_observation: { status: 'needs_review', ready: false, issues: [issue] }, issues: [issue] }} />);
        expect(screen.getByText('Needs attention')).toBeVisible();
        expect(screen.getByText('Audience signals are restricting Search reach.')).toBeVisible();
        expect(screen.getByText('Audience settings')).toBeVisible();
        expect(screen.queryByText(/last check confirmed the applicable/)).toBeNull();
    });

    it('requires audience evidence before an older readiness snapshot can be verified', () => {
        const { audience_observation, ...legacy } = healthy;
        render(<GoogleReadinessCard readiness={legacy} />);
        expect(screen.getByText('Checks pending')).toBeVisible();
        expect(screen.getByText('Not checked')).toBeVisible();
        expect(screen.queryByText(/last check confirmed the applicable/)).toBeNull();
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
