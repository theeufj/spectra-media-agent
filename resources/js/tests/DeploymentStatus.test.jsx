import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import DeploymentStatus from '@/Pages/Campaigns/DeploymentStatus';

const toast = vi.hoisted(() => ({ success: vi.fn() }));
vi.mock('@/Components/Toast', () => ({ useToast: () => toast }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }) => <a href={href}>{children}</a>,
    router: { reload: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { auth: { user: {} } } }),
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ header, children }) => <>{header}{children}</> }));

const campaign = { id: 59, uuid: 'campaign-uuid', name: 'Onboarding New Customer' };
const deployment = { id: 769, platform: 'Google Ads (SEM)', status: 'deploying', progress: 2, ad_copies_count: 1 };
const result = (status, progress) => ({ deployments: [{ ...deployment, status, progress }] });
const response = data => ({ ok: true, status: 200, text: async () => JSON.stringify(data) });
const liveDeployment = { ...deployment, status: 'active', progress: 3, deployment_complete: true,
    delivery_status: { status: 'live', checked_at: '2026-10-24T03:00:00Z' },
    google_readiness: { status: 'pending', ready: false, issues: [], pending: [{ code: 'ad_strength_strength_pending', message: 'Google has not confirmed ad strength yet.' }],
        conversion_goals: { status: 'ready', ready: true }, audience_observation: { status: 'ready', ready: true },
        ad_strength: { checked: true, verified: false, unresolved: [{ reason: 'strength_pending', waiting: true }] } } };

beforeEach(() => {
    vi.useFakeTimers();
    toast.success.mockClear();
    router.reload.mockImplementation(options => options?.onSuccess?.());
    vi.stubGlobal('fetch', vi.fn());
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('deployment completion feedback', () => {
    it.each(['queued', 'pending', 'deploying'])('keeps paused setup creation %s in progress without a premature warning', async status => {
        fetch.mockReturnValue(new Promise(() => {}));
        render(<DeploymentStatus campaign={{ ...campaign, status: 'paused' }} setupOnly deployments={[{
            ...deployment, status, progress: status === 'deploying' ? 2 : 0, images_count: 9, videos_count: 0,
            deployment_complete: false, delivery_status: { status: 'paused' },
        }]} />);

        expect(screen.getByRole('heading', { name: 'Setup progress' })).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Google Search ads' })).toBeVisible();
        expect(screen.getByText('1 ad · 9 images')).toBeVisible();
        expect(screen.queryByText(/0 videos|ad copies/)).toBeNull();
        expect(screen.getByText(/We’ll check the ads and settings in Google once creation finishes/)).toBeVisible();
        expect(screen.queryByRole('heading', { name: /Needs a closer look/ })).toBeNull();
        expect(screen.queryByText(/Deployment finished/)).toBeNull();
        expect(screen.queryByRole('region', { name: 'Google campaign readiness' })).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Your paused ads have been created' })).toBeNull();
        if (status === 'deploying') expect(screen.getAllByText('Creating Google Ads')).toHaveLength(2);
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('continues checking created paused ads until verification and then offers handover without requiring launch checks', async () => {
        const created = { ...deployment, status: 'deployed', progress: 3, deployment_complete: false,
            delivery_status: { status: 'paused' }, google_readiness: null, conversion_goal_readiness: null };
        const verified = { ...created, status: 'verified', progress: 4, deployment_complete: true,
            configuration_verification: { passed: true, issues: [], scope: 'paused_objects' } };
        fetch.mockResolvedValueOnce(response({ deployments: [created] })).mockResolvedValue(response({ deployments: [verified] }));
        render(<DeploymentStatus campaign={{ ...campaign, status: 'paused' }} setupOnly deployments={[created]} />);

        expect(screen.getByText('75%')).toBeVisible();
        expect(screen.getByText('Checking created ads')).toBeVisible();
        expect(screen.getByText('Checking your created campaign and ads')).toBeVisible();
        expect(screen.queryByRole('link', { name: 'View handover checklist →' })).toBeNull();
        expect(screen.queryByRole('heading', { name: /Needs a closer look/ })).toBeNull();
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(fetch).toHaveBeenCalledTimes(1);
        await act(() => vi.advanceTimersByTimeAsync(15000));

        expect(screen.getByText('100%')).toBeVisible();
        expect(screen.getByText('Paused ads ready')).toBeVisible();
        expect(screen.getByRole('link', { name: 'View handover checklist →' })).toHaveAttribute('href', '/dashboard');
        expect(screen.getByText('Creation is verified. Conversion tracking and other launch checks are separate.')).toBeVisible();
        expect(screen.queryByText('Pre-launch checks pending')).toBeNull();
        expect(screen.queryByText(/^Live$/)).toBeNull();
        expect(screen.queryByRole('heading', { name: /Needs a closer look/ })).toBeNull();
        await act(() => vi.advanceTimersByTimeAsync(30000));
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('does not accept old completion evidence while ads are being created again', async () => {
        fetch.mockReturnValue(new Promise(() => {}));
        render(<DeploymentStatus campaign={campaign} setupOnly deployments={[{
            ...deployment, progress: 4, deployment_complete: true, delivery_status: { status: 'paused' },
        }]} />);
        expect(screen.getByText('50%')).toBeVisible();
        expect(screen.queryByText('100%')).toBeNull();
        expect(screen.queryByText('Paused ads ready')).toBeNull();
        expect(screen.queryByRole('link', { name: 'View handover checklist →' })).toBeNull();
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('keeps verification in progress when another platform has already reported an issue', () => {
        fetch.mockReturnValue(new Promise(() => {}));
        render(<DeploymentStatus campaign={campaign} setupOnly deployments={[
            { ...deployment, status: 'deployed', progress: 3, deployment_complete: false, delivery_status: { status: 'paused' } },
            { id: 770, platform: 'Facebook Ads', status: 'deploy_unverified', progress: 2 },
        ]} />);
        expect(screen.getByText('Checking created ads')).toBeVisible();
        expect(screen.queryByRole('heading', { name: /Needs a closer look/ })).toBeNull();
        expect(screen.queryByText(/Deployment finished/)).toBeNull();
    });

    it('shows an actual setup failure separately from checks that have not started', () => {
        render(<DeploymentStatus campaign={campaign} setupOnly deployments={[{
            ...deployment, status: 'failed', error_message: 'Google could not create the ad.',
            deployment_complete: false, delivery_status: { status: 'needs_review' },
        }]} />);
        expect(screen.getByRole('heading', { name: 'Ad setup needs attention' })).toBeVisible();
        expect(screen.getByText('Google could not create the ad.')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Retry ad creation' })).toBeVisible();
        expect(screen.queryByText('Checking your created campaign and ads')).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Your paused ads have been created' })).toBeNull();
    });

    it.each([['queued', 0], ['deploying', 2], ['pending', 0]])('clears an earlier live presentation when Echo starts new %s work and resumes polling', async (status, progress) => {
        const listeners = {};
        const channel = { listen: (name, listener) => { listeners[name] = listener; return channel; }, stopListening: vi.fn() };
        vi.stubGlobal('Echo', { private: vi.fn(() => channel) });
        fetch.mockReturnValue(new Promise(() => {}));
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[liveDeployment]} />);
        expect(screen.getByText('100%')).toBeInTheDocument();
        expect(fetch).not.toHaveBeenCalled();

        act(() => listeners['.deployment.progress']({ strategy_id: deployment.id, deployment: { status } }));

        expect(screen.queryByText(/^Live$/)).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Your campaign is live' })).toBeNull();
        expect(screen.getByText(`${progress * 25}%`)).toBeInTheDocument();
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('preserves current live proof when a late completion event lacks new presentation', async () => {
        const listeners = {};
        const channel = { listen: (name, listener) => { listeners[name] = listener; return channel; }, stopListening: vi.fn() };
        vi.stubGlobal('Echo', { private: vi.fn(() => channel) });
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[liveDeployment]} />);

        act(() => {
            listeners['.deployment.completed']({ strategy_id: deployment.id });
            listeners['.deployment.progress']({ strategy_id: deployment.id, deployment: { status: 'deployed', progress: 3 } });
        });

        expect(screen.getByRole('heading', { name: 'Your campaign is live' })).toBeVisible();
        expect(screen.getByText('100%')).toBeInTheDocument();
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(fetch).not.toHaveBeenCalled();
    });

    it('shows confirmed live delivery at 100% while ad strength waits in a separate neutral card', async () => {
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[liveDeployment]} />);
        expect(screen.getAllByText(/^Live$/)).toHaveLength(2);
        expect(screen.getByRole('heading', { name: 'Your campaign is live' })).toBeVisible();
        expect(screen.getByText('100%')).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Google ad quality' })).toHaveClass('bg-slate-50');
        expect(screen.getAllByText('Ad strength pending')).toHaveLength(2);
        expect(screen.queryByText(/not fully verified/)).toBeNull();
        expect(screen.queryByText(/Deployed · verifying/)).toBeNull();
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Refresh readiness status' })).toBeVisible();
    });

    it.each(['under_review', 'paused', 'unknown', 'needs_review'])('does not claim live for observed %s delivery', status => {
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[{
            ...liveDeployment, deployment_complete: false, delivery_status: { status },
        }]} />);
        expect(screen.queryByText(/^Live$/)).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Your campaign is live' })).toBeNull();
        expect(screen.getByText('75%')).toBeInTheDocument();
    });

    it('does not call a multi-platform campaign live while another platform is incomplete', () => {
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[
            liveDeployment, { ...deployment, id: 770, platform: 'Facebook Ads', status: 'deploying', progress: 2, deployment_complete: false,
                delivery_status: { status: 'unknown' } },
        ]} />);
        expect(screen.getAllByText(/^Live$/)).toHaveLength(1);
        expect(screen.queryByRole('heading', { name: 'Your campaign is live' })).toBeNull();
        expect(screen.getByText('75%')).toBeInTheDocument();
        expect(screen.getAllByText(/Creating ads/)).toHaveLength(2);
    });

    it('waits for current delivery proof on a terminal raw status, then stops without waiting for ad strength', async () => {
        fetch.mockResolvedValueOnce(response({ deployments: [liveDeployment] }));
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[{
            ...liveDeployment, deployment_complete: false, delivery_status: { status: 'unknown' },
        }]} />);
        expect(screen.getByText('75%')).toBeInTheDocument();
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(screen.getByRole('heading', { name: 'Your campaign is live' })).toBeVisible();
        expect(screen.getByText('100%')).toBeInTheDocument();
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('keeps completed construction at 100% under real policy review and observes approval before showing Live', async () => {
        const underReview = { ...liveDeployment, delivery_status: { status: 'under_review' } };
        fetch.mockResolvedValueOnce(response({ deployments: [underReview] }))
            .mockResolvedValue(response({ deployments: [liveDeployment] }));
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[underReview]} />);
        expect(screen.getByText('100%')).toBeInTheDocument();
        expect(screen.getAllByText('Waiting for Google review')).toHaveLength(2);
        expect(screen.queryByText(/^Live$/)).toBeNull();
        await act(() => vi.advanceTimersByTimeAsync(0));
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(screen.getByRole('heading', { name: 'Your campaign is live' })).toBeVisible();
        await act(() => vi.advanceTimersByTimeAsync(60000));
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('does not accept contradictory live delivery without completed deployment proof', () => {
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[{
            ...liveDeployment, deployment_complete: false,
        }]} />);
        expect(screen.queryByText(/^Live$/)).toBeNull();
        expect(screen.getByText('75%')).toBeInTheDocument();
    });

    it('never relabels a setup-only paused handover as live from inconsistent serving data', () => {
        render(<DeploymentStatus campaign={{ ...campaign, status: 'paused' }} deployments={[liveDeployment]} setupOnly />);
        expect(screen.queryByText(/^Live$/)).toBeNull();
        expect(screen.getByRole('heading', { name: 'Your paused ads have been created' })).toBeVisible();
        expect(screen.getByText(/Your ads remain paused/)).toBeVisible();
    });

    it('keeps legacy terminal behavior for other platforms without a delivery presentation', async () => {
        render(<DeploymentStatus campaign={{ ...campaign, status: 'active' }} deployments={[{
            ...deployment, platform: 'Facebook Ads', status: 'active', progress: 3, deployment_complete: false, delivery_status: null,
        }]} />);
        expect(screen.getByText('75%')).toBeInTheDocument();
        expect(screen.queryByText(/^Live$/)).toBeNull();
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(fetch).not.toHaveBeenCalled();
    });

    it('announces deployment immediately, then confirms verification and stops polling', async () => {
        fetch.mockResolvedValueOnce(response(result('deployed', 3)))
            .mockResolvedValue(response(result('verified', 4)));
        render(<DeploymentStatus campaign={campaign} deployments={[deployment]} />);
        expect(screen.getByText('50%')).toBeInTheDocument();
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(screen.getByRole('heading', { name: 'Your campaign has been deployed' })).toBeVisible();
        expect(screen.getByText(/We are confirming the campaign/)).toBeVisible();
        expect(screen.getByText('75%')).toBeInTheDocument();
        expect(toast.success).toHaveBeenCalledTimes(1);

        await act(() => vi.advanceTimersByTimeAsync(3000));
        expect(screen.getByText('100%')).toBeInTheDocument();
        expect(screen.getByText('The campaign and ads have also been verified on the platform.')).toBeVisible();
        expect(screen.queryByText(/begin serving from tomorrow/)).toBeNull();
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(toast.success).toHaveBeenCalledTimes(1);
    });

    it('shows lost status updates instead of silently leaving the page at 50%, then recovers', async () => {
        fetch.mockResolvedValue({ ok: false, status: 401, text: async () => '{}' });
        render(<DeploymentStatus campaign={campaign} deployments={[deployment]} />);
        await act(() => vi.advanceTimersByTimeAsync(9000));
        expect(screen.getByRole('alert')).toHaveTextContent('Your session expired');
        expect(screen.getByText(/Status updates unavailable/)).toBeInTheDocument();
        expect(screen.getByText('50%')).toBeInTheDocument();

        fetch.mockResolvedValue(response(result('verified', 4)));
        fireEvent.click(screen.getByRole('button', { name: 'Check status again' }));
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(router.reload).toHaveBeenCalled();
        expect(screen.queryByRole('alert')).toBeNull();
        expect(screen.getByText('100%')).toBeInTheDocument();
    });

    it('bounds retries and preserves progress when status requests never return', async () => {
        fetch.mockReturnValue(new Promise(() => {}));
        render(<DeploymentStatus campaign={campaign} deployments={[deployment]} />);
        await act(() => vi.advanceTimersByTimeAsync(15 * 60 * 1000));
        expect(screen.getByRole('alert')).toHaveTextContent('last update received');
        expect(fetch).toHaveBeenCalledTimes(4);
        expect(screen.getByText('50%')).toBeInTheDocument();
        await act(() => vi.advanceTimersByTimeAsync(15 * 60 * 1000));
        expect(fetch).toHaveBeenCalledTimes(4);
    });

    it('uses fresh Inertia props and preserves the paused promise for setup-only campaigns', () => {
        fetch.mockReturnValue(new Promise(() => {}));
        const { rerender } = render(<DeploymentStatus campaign={campaign} deployments={[deployment]} setupOnly />);
        rerender(<DeploymentStatus campaign={campaign} deployments={[{ ...deployment, status: 'verified', progress: 4 }]} setupOnly />);
        expect(screen.getByRole('heading', { name: 'Your paused ads have been created' })).toBeVisible();
        expect(screen.getByText(/Your ads remain paused/)).toBeVisible();
        expect(screen.getByText('100%')).toBeInTheDocument();
    });

    it('does not claim complete success when another platform still needs verification', () => {
        render(<DeploymentStatus campaign={campaign} deployments={[
            { ...deployment, status: 'verified', progress: 4 },
            { ...deployment, id: 770, platform: 'Facebook Ads', status: 'deploy_unverified', progress: 2 },
        ]} />);
        expect(screen.getByRole('heading', { name: /Needs a closer look/ })).toBeVisible();
        expect(screen.queryByRole('heading', { name: 'Your campaign has been deployed' })).toBeNull();
        expect(toast.success).not.toHaveBeenCalled();
    });

    it('offers the verified paused handover while keeping conversion blockers visible', () => {
        render(<DeploymentStatus campaign={campaign} setupOnly deployments={[{
            ...deployment, status: 'verified', progress: 4,
            conversion_goal_readiness: { status: 'needs_review', ready: false,
                issues: [{ code: 'conversion_goal_primary_ambiguous', message: 'The intended goal has no matching enabled primary action.' }] },
        }]} />);

        expect(screen.getByRole('heading', { name: 'Your paused ads have been created' })).toBeVisible();
        expect(screen.getByRole('link', { name: 'View handover checklist →' })).toHaveAttribute('href', '/dashboard');
        expect(screen.getByText('The intended goal has no matching enabled primary action.')).toBeVisible();
        expect(screen.getByText('Needs review')).toBeVisible();
        expect(screen.getByText(/Created ads and a verified deployment do not confirm readiness to deliver/)).toBeVisible();
        expect(screen.queryByText(/Deployment Complete!/)).toBeNull();
    });

    it('treats legacy active as terminal deployed with pending readiness, never structural verification', async () => {
        render(<DeploymentStatus campaign={campaign} deployments={[{ ...deployment, status: 'active', progress: 3 }]} />);
        expect(screen.getByText('75%')).toBeInTheDocument();
        expect(screen.getByText(/Deployed · verifying/)).toBeVisible();
        expect(screen.getByText(/Readiness checks pending/)).toBeVisible();
        expect(screen.getByRole('button', { name: 'Refresh readiness status' })).toBeVisible();
        expect(screen.queryByText('The campaign and ads have also been verified on the platform.')).toBeNull();
        expect(screen.queryByText(/Deployment Complete!/)).toBeNull();
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(fetch).not.toHaveBeenCalled();
    });
});
