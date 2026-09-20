import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { fireEvent, render } from '@testing-library/react';

const retryPost = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        post: retryPost,
        processing: false,
        errors: {},
    }),
    router: { post: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {}, url: '/' }),
    Head: () => null,
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/CampaignCopilot', () => ({ default: () => null }));
vi.mock('@/hooks/useJobWatch', () => ({ useJobWatch: () => ({ phase: 'idle', data: null }) }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => ({ data: null }) }));

import Show, { StrategyCard } from '@/Pages/Campaigns/Show';

it('offers a working retry for a failed first generation, before polling', () => {
    retryPost.mockClear();
    const campaign = {
        uuid: 'failed-campaign', name: 'New campaign', strategies: [],
        strategy_generation_started_at: '2026-09-20T08:18:18Z',
        strategy_generation_error: 'Generation failed. Please try again.',
    };
    const { getByRole, getByText } = render(<Show auth={{ user: {} }} campaign={campaign} />);
    expect(getByText('Strategy generation failed')).toBeInTheDocument();
    fireEvent.click(getByRole('button', { name: 'Try generation again' }));
    expect(retryPost).toHaveBeenCalledWith(
        route('campaigns.retry-generation', { campaign: campaign.uuid }),
        expect.objectContaining({ onSuccess: expect.any(Function) })
    );
});

it('shows the saved strategy after Inertia returns updated props without a remount', () => {
    const campaign = {
        uuid: 'campaign-edited', name: 'Edited campaign',
        strategies: [{
            id: 99, uuid: 'strategy-edited', platform: 'Google Ads',
            ad_copy_strategy: 'Original brief', imagery_strategy: 'Property photos', video_strategy: 'N/A',
        }],
    };
    const { rerender, getByText, queryByText } = render(<Show auth={{ user: {} }} campaign={campaign} />);
    expect(getByText('Original brief')).toBeTruthy();

    rerender(<Show auth={{ user: {} }} campaign={{
        ...campaign,
        strategies: [{ ...campaign.strategies[0], ad_copy_strategy: 'Saved listing-specific brief' }],
    }} />);
    expect(getByText('Saved listing-specific brief')).toBeTruthy();
    expect(queryByText('Original brief')).toBeNull();
});

/**
 * The signed-off branch of the strategy card, which nothing rendered until a
 * customer actually approved a campaign.
 *
 * The uuid sweep renamed this component's prop at the call site — campaignId to
 * campaignUuid — and left the signature alone. The one line that needed it then
 * reached for `campaigns`, a variable belonging to the page component, from a
 * component declared at module scope. That is a ReferenceError, and a
 * ReferenceError in render takes the whole page to the error boundary.
 *
 * It sat unnoticed because the link lives behind `isSignedOff`. The crash
 * arrived at the exact moment a paid customer pressed Sign Off on the campaign
 * they had waited for, and at no point before.
 */
describe('StrategyCard, once signed off', () => {
    const strategy = {
        id: 1,
        uuid: 'strat-uuid',
        platform: 'Google Ads',
        signed_off_at: '2026-09-13T06:30:00Z',
        ad_copy_strategy: 'Direct, encouraging search ads.',
        imagery_strategy: 'A maker in a bright studio.',
        video_strategy: 'N/A',
        ad_copies_count: 3,
        image_collaterals_count: 2,
        video_collaterals_count: 0,
    };

    it('renders without reaching for a variable it does not have', () => {
        expect(() =>
            render(<StrategyCard strategy={strategy} campaignUuid="camp-uuid" onSignOff={() => {}} />)
        ).not.toThrow();
    });

    it('links to the collateral using the uuid the page passes it', () => {
        // Asserted on what route() was handed rather than on the href: the test
        // setup's route stub renders its params as [object Object], so the
        // uuid never reaches the markup to be read back.
        const calls = [];
        const original = global.route;
        global.route = (name, params) => {
            calls.push([name, params]);

            return original(name, params);
        };

        render(<StrategyCard strategy={strategy} campaignUuid="camp-uuid" onSignOff={() => {}} />);

        global.route = original;

        const collateral = calls.find(([name]) => name === 'campaigns.collateral.show');

        // Both halves matter: the prop name has to match what the page sends,
        // and the href has to be built from it rather than from an outer scope.
        expect(collateral).toBeDefined();
        expect(collateral[1]).toEqual({ campaign: 'camp-uuid', strategy: 'strat-uuid' });
    });
});
