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
const strategy = { id: 11, uuid: 'strategy-a', platform: 'Facebook Ads', signed_off_at: '2026-10-01', campaign_type: 'display', ad_copies_count: 1, image_collaterals_count: 0, video_collaterals_count: 0 };
const copy = { id: 14, strategy_id: 11, platform: 'Facebook Ads', headlines: ['Repair your sailboat'], descriptions: ['Book a service'], should_deploy: false, updated_at: '2026-10-01' };
const props = { campaign: { id: 8, uuid: 'campaign-a', name: 'Repair campaign', daily_budget: 20, total_budget: 600 }, currentStrategy: strategy, allStrategies: [strategy], adCopy: copy, imageCollaterals: [], videoCollaterals: [], supportsVideo: false, hasActiveSubscription: true, deploymentEnabled: true, brandVerified: true, managedBillingEnabled: false };

beforeEach(() => { polling.data = null; vi.clearAllMocks(); });

describe('saved creative selections', () => {
    it('saves every format of deselected concepts and retains the saved exclusion after a reload', async () => {
        const images = Array.from({ length: 9 }, (_, index) => ({ id: 749 + index,
            concept_key: `concept-${Math.floor(index / 3)}`, format: ['square', 'landscape', 'mrec'][index % 3],
            cloudfront_url: `https://assets.example/${index}.png`, should_deploy: true, is_active: true }));
        const saved = images.map(image => ({ ...image }));
        fetchJson.mockImplementation(async (_url, { json }) => {
            for (const row of saved) if (json.ids.includes(row.id)) row[json.field] = json.value;
            return { rows: json.ids.map(id => ({ id, [json.field]: json.value })) };
        });
        const view = render(<Collateral {...props} imageCollaterals={images} />);
        for (let index = 0; index < 3; index++) {
            fireEvent.click(view.getAllByRole('checkbox', { name: 'Include this image in deployment' })[index]);
            await waitFor(() => expect(view.getAllByRole('checkbox', { name: 'Include this image in deployment' })[index]).toHaveAttribute('aria-checked', 'false'));
            expect(fetchJson.mock.calls[index]).toEqual([route('campaigns.collateral-approval.update', props.campaign.uuid), {
                method: 'PUT', json: { type: 'image', ids: images.slice(index * 3, index * 3 + 3).map(image => image.id), field: 'should_deploy', value: false },
            }]);
        }
        expect(fetchJson).toHaveBeenCalledTimes(3);
        expect(saved.every(image => image.should_deploy === false)).toBe(true);
        expect(view.getByText('0/3 image concepts')).toBeVisible();
        view.unmount();
        const reloaded = render(<Collateral {...props} imageCollaterals={saved} />);
        expect(reloaded.getAllByRole('checkbox', { name: 'Include this image in deployment' }).every(checkbox => checkbox.getAttribute('aria-checked') === 'false')).toBe(true);
        expect(reloaded.getByText('0/3 image concepts')).toBeVisible();
    });

    it('updates both Search previews when an image is excluded and never shows an unselected or inactive asset', async () => {
        const search = { ...strategy, platform: 'Google Ads', campaign_type: 'search' };
        const images = [
            { id: 749, concept_key: 'selected', format: 'square', should_deploy: true, is_active: true, cloudfront_url: 'https://assets.example/selected.png' },
            { id: 750, concept_key: 'excluded', format: 'square', should_deploy: false, is_active: true, cloudfront_url: 'https://assets.example/excluded.png' },
            { id: 751, concept_key: 'inactive', format: 'square', should_deploy: true, is_active: false, cloudfront_url: 'https://assets.example/inactive.png' },
        ];
        fetchJson.mockResolvedValue({ rows: [{ id: 749, should_deploy: false }] });
        const view = render(<Collateral {...props} currentStrategy={search} allStrategies={[search]}
            adCopy={{ ...copy, platform: 'Google Ads' }} imageCollaterals={images} />);
        fireEvent.click(view.getByRole('button', { name: '👁️ Preview Ad' }));
        const previews = view.getAllByText('Ad Preview').map(heading => heading.closest('.bg-gray-100'));
        expect(previews).toHaveLength(2);
        for (const preview of previews) {
            expect(within(preview).getByRole('img')).toHaveAttribute('src', 'https://assets.example/selected.png');
            expect(within(preview).getByText(/Search images require account eligibility and attachment in Google Ads/)).toBeVisible();
        }
        expect(view.getByText('Select images to save to your Google Ads asset library. Attach eligible images to your Search ads in Google Ads.')).toBeVisible();
        fireEvent.click(view.getAllByRole('checkbox', { name: 'Include this image in deployment' })[0]);
        await waitFor(() => expect(view.getAllByRole('checkbox', { name: 'Include this image in deployment' })[0]).toHaveAttribute('aria-checked', 'false'));
        for (const preview of previews) expect(within(preview).queryByRole('img')).toBeNull();
        expect(fetchJson).toHaveBeenCalledExactlyOnceWith(route('campaigns.collateral-approval.update', props.campaign.uuid), {
            method: 'PUT', json: { type: 'image', ids: [749], field: 'should_deploy', value: false },
        });
    });

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
    it('preserves the current campaign review page when launch requires brand approval', () => {
        const calls = [];
        const originalRoute = globalThis.route;
        globalThis.route = (name, params) => { calls.push([name, params]); return originalRoute(name, params); };
        try {
            const view = render(<Collateral {...props} brandVerified={false} />);
            fireEvent.click(view.getByRole('button', { name: 'Launch Facebook ads' }));
            expect(calls).toContainEqual(['brand-guidelines.index', {
                review: 1, return_campaign: props.campaign.uuid, return_strategy: strategy.uuid,
            }]);
            expect(router.visit).toHaveBeenCalledExactlyOnceWith(expect.stringContaining('brand-guidelines.index'));
            expect(router.post).not.toHaveBeenCalled();
        } finally {
            globalThis.route = originalRoute;
        }
    });

    it('preserves its selected strategy through funding and explicit launch consent', () => {
        const { getByRole, getByText } = render(<Collateral {...props} managedBillingEnabled />);
        fireEvent.click(getByRole('button', { name: 'Launch Facebook ads' }));
        expect(getByText('Fund Facebook Ads')).toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(getByRole('button', { name: 'Funding succeeded' }));
        expect(getByRole('dialog')).toHaveTextContent('Launch Facebook ads?');
        fireEvent.click(within(getByRole('dialog')).getByRole('button', { name: 'Launch Facebook ads' }));
        expect(router.post).toHaveBeenCalledWith('/__route__/deployment.deploy-platform', { campaign_id: 8, strategy_id: 11 }, expect.any(Object));
    });

    it('uses one clear Google setup action without a dropdown and keeps explicit paused-ad consent', () => {
        const google = { ...strategy, platform: 'Google Ads (SEM)', campaign_type: 'search' };
        const { getByRole, queryByRole, getByText } = render(<Collateral {...props} currentStrategy={google}
            allStrategies={[google]} adCopy={{ ...copy, platform: 'Google Ads' }} setupOnly managedBillingEnabled />);
        expect(queryByRole('button', { name: 'Choose an ad platform' })).toBeNull();
        expect(getByText('Your ads will stay paused until you start them.')).toBeVisible();
        expect(getByRole('button', { name: 'Create Google Search ads' })).toHaveAttribute('aria-describedby', 'paused-ads-explanation');
        fireEvent.click(getByRole('button', { name: 'Create Google Search ads' }));
        expect(router.post).not.toHaveBeenCalled();
        expect(getByRole('dialog')).toHaveTextContent('Create Google Search ads?');
        expect(getByRole('dialog')).toHaveTextContent('in your Google Ads account and leave them paused');
        expect(getByRole('dialog')).toHaveTextContent('Nothing spends until you switch them on');
        fireEvent.click(within(getByRole('dialog')).getByRole('button', { name: 'Create Google Search ads' }));
        expect(router.post).toHaveBeenCalledWith('/__route__/deployment.deploy-platform', { campaign_id: 8, strategy_id: 11 }, expect.any(Object));
    });

    it('keeps platform selection when several approved platforms can be created', () => {
        const google = { ...strategy, id: 12, uuid: 'google-strategy', platform: 'Google Ads', campaign_type: 'search' };
        const { getByRole } = render(<Collateral {...props} allStrategies={[strategy, google]} setupOnly />);
        expect(getByRole('button', { name: 'Create all ads' })).toBeVisible();
        fireEvent.click(getByRole('button', { name: 'Choose an ad platform' }));
        fireEvent.click(getByRole('button', { name: 'Google Search ads' }));
        expect(getByRole('dialog')).toHaveTextContent('Create Google Search ads?');
        expect(getByRole('dialog')).toHaveTextContent('leave them paused');
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(within(getByRole('dialog')).getByRole('button', { name: 'Create Google Search ads' }));
        expect(router.post).toHaveBeenCalledExactlyOnceWith('/__route__/deployment.deploy-platform', { campaign_id: 8, strategy_id: 12 }, expect.any(Object));
    });

    it('retains the all-platform route only for an explicit all-platform confirmation', () => {
        const google = { ...strategy, id: 12, uuid: 'google-strategy', platform: 'Google Ads', campaign_type: 'search' };
        const { getByRole } = render(<Collateral {...props} allStrategies={[strategy, google]} setupOnly />);
        fireEvent.click(getByRole('button', { name: 'Create all ads' }));
        expect(getByRole('dialog')).toHaveTextContent('across your selected platforms and leave them paused');
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(within(getByRole('dialog')).getByRole('button', { name: 'Create all ads' }));
        expect(router.post).toHaveBeenCalledExactlyOnceWith('/__route__/deployment.deploy', { campaign_id: 8 }, expect.any(Object));
    });

    it('offers the sole signed-off strategy without presenting unsigned directions as launch choices', () => {
        const unsigned = { ...strategy, id: 12, uuid: 'unsigned-google', platform: 'Google Ads', signed_off_at: null };
        const { getByRole, queryByRole } = render(<Collateral {...props} allStrategies={[strategy, unsigned]} />);
        expect(queryByRole('button', { name: 'Choose an ad platform' })).toBeNull();
        fireEvent.click(getByRole('button', { name: 'Launch Facebook ads' }));
        fireEvent.click(within(getByRole('dialog')).getByRole('button', { name: 'Launch Facebook ads' }));
        expect(router.post).toHaveBeenCalledExactlyOnceWith('/__route__/deployment.deploy-platform', { campaign_id: 8, strategy_id: 11 }, expect.any(Object));
    });

    it('describes an existing single-platform setup as an update and still leaves it paused', () => {
        const google = { ...strategy, platform: 'Google Ads', deployment_status: 'verified' };
        const { getByRole } = render(<Collateral {...props} currentStrategy={google} allStrategies={[google]} setupOnly />);
        fireEvent.click(getByRole('button', { name: 'Update Google ads' }));
        expect(getByRole('dialog')).toHaveTextContent("We'll update your selected ads in your Google Ads account and leave them paused");
        expect(router.post).not.toHaveBeenCalled();
    });

    it.each([
        { hasActiveSubscription: false }, { deploymentEnabled: false },
        { campaign: { ...props.campaign, auto_generated_at: '2026-10-30', budget_confirmed_at: null } },
    ])('retains access and approval gates with the simplified single action %j', overrides => {
        const { getByRole, queryByRole } = render(<Collateral {...props} {...overrides} />);
        fireEvent.click(getByRole('button', { name: 'Launch Facebook ads' }));
        expect(queryByRole('dialog')).toBeNull();
        expect(router.post).not.toHaveBeenCalled();
    });
});
