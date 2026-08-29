import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, fireEvent } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('@/Components/SubscriptionTierSelector', () => ({
    default: () => <div data-testid="tiers" />,
}));

import Pricing from '@/Pages/Subscription/Pricing';
import { router } from '@inertiajs/react';

const auth = { user: { subscription_plan: null } };

describe('Pricing one-time setup card', () => {
    beforeEach(() => router.post.mockReset());

    it('offers the one-time setup with its USD price and starts checkout', () => {
        const { getByText } = render(
            <Pricing auth={auth} plans={[]} setupFee={{ price_usd: 999, intent: false, paid: false }} />
        );

        expect(getByText('One-time Google Ads setup')).toBeInTheDocument();
        expect(getByText('US$999')).toBeInTheDocument();

        fireEvent.click(getByText('Pay setup fee'));
        expect(router.post).toHaveBeenCalledWith('/__route__/setup-fee.checkout');
    });

    it('shows the paid state instead of the offer once the fee is settled', () => {
        const { getByText, queryByText } = render(
            <Pricing auth={auth} plans={[]} setupFee={{ price_usd: 999, intent: true, paid: true }} />
        );

        expect(getByText('Your one-time setup is paid ✓')).toBeInTheDocument();
        expect(queryByText('Pay setup fee')).toBeNull();
    });

    it('renders nothing extra when the fee prop is absent', () => {
        const { queryByText } = render(<Pricing auth={auth} plans={[]} />);

        expect(queryByText('One-time Google Ads setup')).toBeNull();
    });
});
