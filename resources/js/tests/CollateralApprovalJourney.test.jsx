import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, renderHook, waitFor, within } from '@testing-library/react';
import Collateral from '@/Pages/Campaigns/Collateral';
import { useCollateralGeneration } from '@/hooks/useCollateralGeneration';
import { fetchJson } from '@/utils/http';
import { router } from '@inertiajs/react';
const polling = vi.hoisted(() => ({ data: null, failureStreak: 0 }));
const toast = vi.hoisted(() => ({ error: vi.fn(), info: vi.fn(), warning: vi.fn(), success: vi.fn() }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => polling }));
vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, href }) => <a href={href}>{children}</a>, usePage: () => ({ props: { auth: { user: { subscription_status: 'active' } } } }), router: { post: vi.fn(), visit: vi.fn() } }));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children, header }) => <>{header}{children}</> }));
vi.mock('@/hooks/useCurrency', () => ({ useCurrency: () => 'AUD' }));
vi.mock('@/Components/Toast', () => ({ useToast: () => toast }));
vi.mock('@/Components/RefineImageModal', () => ({ default: () => null }));
vi.mock('@/Components/ExtendVideoModal', () => ({ default: () => null }));
vi.mock('@/Components/SubscriptionRequiredModal', () => ({ default: () => null }));
vi.mock('@/Components/DeploymentDisabledModal', () => ({ default: () => null }));
vi.mock('@/Components/AdSpendSetupModal', () => ({ default: ({ show, strategy, onSuccess }) => show ? <section><p>Fund {strategy?.platform || 'all'}</p><button onClick={() => onSuccess({ credit_amount: 30, new_balance: 140 })}>Funding succeeded</button></section> : null }));
vi.mock('@/Components/ConfirmationModal', () => ({ default: ({ show, title, message, onConfirm, confirmText }) => show ? <section role="dialog"><h2>{title}</h2><p>{message}</p><button onClick={onConfirm}>{confirmText}</button></section> : null }));
vi.mock('@/Components/Modal', () => ({ default: () => null }));
vi.mock('@/Components/CreativeSizesModal', () => ({ default: () => null }));
vi.mock('@/Components/AdPreview', () => ({ default: () => null }));
const strategy = { id: 11, uuid: 'strategy-a', platform: 'Facebook Ads', signed_off_at: '2026-10-01', campaign_type: 'display', ad_copies_count: 1, image_collaterals_count: 0, video_collaterals_count: 0 };
const copy = { id: 14, strategy_id: 11, platform: 'Facebook Ads', headlines: ['Repair your sailboat'], descriptions: ['Book a service'], should_deploy: false, updated_at: '2026-10-01' };
const props = { campaign: { id: 8, uuid: 'campaign-a', name: 'Repair campaign', daily_budget: 20, total_budget: 600 }, currentStrategy: strategy, allStrategies: [strategy], adCopy: copy, imageCollaterals: [], videoCollaterals: [], supportsVideo: false, hasActiveSubscription: true, deploymentEnabled: true, brandVerified: true, managedBillingEnabled: false };

beforeEach(() => { polling.data = null; vi.clearAllMocks(); });

describe('saved creative selections', () => {
    it('keeps confirmed checkbox state when persistence fails and offers a visible recovery', async () => {
        fetchJson.mockRejectedValue(new Error('offline'));
        const { getByRole } = render(<Collateral {...props} />);
        fireEvent.click(getByRole('checkbox', { name: 'Include this ad copy in deployment' }));
        await waitFor(() => expect(getByRole('alert')).toHaveTextContent('previous saved selection'));
        expect(getByRole('checkbox', { name: 'Include this ad copy in deployment' })).toHaveAttribute('aria-checked', 'false');
        fetchJson.mockResolvedValue({ rows: [{ id: 14, should_deploy: true }] });
        fireEvent.click(getByRole('checkbox', { name: 'Include this ad copy in deployment' }));
        await waitFor(() => expect(getByRole('checkbox', { name: 'Include this ad copy in deployment' })).toHaveAttribute('aria-checked', 'true'));
    });

    it('does not let an in-flight generation snapshot undo a saved approval', () => {
        const input = { ...props, generationPending: true };
        const { result, rerender } = renderHook(() => useCollateralGeneration(input));
        act(() => result.current.applySavedSelection('ad_copy', [{ id: 14, should_deploy: true }]));
        polling.data = { adCopy: { ...copy }, imageCollaterals: [], videoCollaterals: [], collateralErrors: [], creativeReview: null, generationPending: true };
        rerender();
        expect(result.current.collateral.adCopy.should_deploy).toBe(true);
    });
});

describe('one platform launch consent', () => {
    it('preserves its selected strategy through funding and explicit launch consent', () => {
        const { getByRole, getByText } = render(<Collateral {...props} managedBillingEnabled />);
        fireEvent.click(getByRole('button', { name: 'Deploy individual platform' }));
        fireEvent.click(getByRole('button', { name: 'Facebook Ads' }));
        expect(getByText('Fund Facebook Ads')).toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(getByRole('button', { name: 'Funding succeeded' }));
        expect(getByRole('dialog')).toHaveTextContent('Launch ads — Facebook Ads');
        fireEvent.click(getByRole('button', { name: 'Launch selected ads' }));
        expect(router.post).toHaveBeenCalledWith('/__route__/deployment.deploy-platform', { campaign_id: 8, strategy_id: 11 }, expect.any(Object));
    });

    it('states that setup-only ads remain paused for either launch path', () => {
        const { getByRole } = render(<Collateral {...props} setupOnly managedBillingEnabled />);
        fireEvent.click(getByRole('button', { name: 'Create ads for one platform' }));
        fireEvent.click(getByRole('button', { name: 'Facebook Ads' }));
        expect(getByRole('dialog')).toHaveTextContent('Nothing spends until you switch them on');
        fireEvent.click(within(getByRole('dialog')).getByRole('button', { name: 'Create paused ads' }));
        expect(router.post).toHaveBeenCalledWith('/__route__/deployment.deploy-platform', { campaign_id: 8, strategy_id: 11 }, expect.any(Object));
    });
});
