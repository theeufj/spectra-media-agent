import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen } from '@testing-library/react';
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

beforeEach(() => {
    vi.useFakeTimers();
    toast.success.mockClear();
    vi.stubGlobal('fetch', vi.fn());
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('deployment completion feedback', () => {
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
        await act(() => vi.advanceTimersByTimeAsync(3000));
        expect(screen.queryByRole('alert')).toBeNull();
        expect(screen.getByText('100%')).toBeInTheDocument();
    });

    it('times out even if a status request never returns', async () => {
        fetch.mockReturnValue(new Promise(() => {}));
        render(<DeploymentStatus campaign={campaign} deployments={[deployment]} />);
        await act(() => vi.advanceTimersByTimeAsync(15 * 60 * 1000));
        expect(screen.getByRole('alert')).toHaveTextContent('last update received');
        expect(fetch).toHaveBeenCalledTimes(1);
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
});
