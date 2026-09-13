import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
    router: { post: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {}, url: '/' }),
    Head: () => null,
}));

import { StrategyCard } from '@/Pages/Campaigns/Show';

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
