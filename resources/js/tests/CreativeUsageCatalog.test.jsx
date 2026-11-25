import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import CreativeUsage from '@/Pages/CreativeUsage';

vi.mock('@/Layouts/AuthenticatedLayout', () => ({
    default: ({ children }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    router: { post: vi.fn() },
}));

const allowance = { used: 12, limit: 12, bonus: 0, remaining: 0 };
const props = {
    auth: { user: {} },
    creativeUsage: {
        plan_name: 'Starter', period: '2026-11', is_unlimited: false,
        image_generations: { ...allowance, bonus: 25, remaining: 25 },
        video_generations: allowance, refinements: allowance,
    },
    purchases: [{ id: 1, created_at: '2026-11-01', period: '2026-11', amount_cents: 2900,
        image_generations: 25, video_generations: 5, refinements: 25 }],
};

describe('creative allowance and past purchases', () => {
    it('shows the bonus balance and receipt without offering a new boost in a restricted catalog', () => {
        render(<CreativeUsage {...props} boostConfig={null} />);
        expect(screen.getByText('(+25 bonus)')).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Boost Purchase History' })).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Buy Boost Pack' })).toBeNull();
        expect(screen.queryByText(/available on growth/i)).toBeNull();
    });

    it('starts checkout when the server offers the boost', () => {
        render(<CreativeUsage {...props} boostConfig={{ price_cents: 2900, image_generations: 25, video_generations: 5, refinements: 25 }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Buy Boost Pack' }));
        expect(router.post).toHaveBeenCalledWith('/__route__/creative-boost.checkout');
    });
});
