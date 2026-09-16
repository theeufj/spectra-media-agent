import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { UnlockAction } from '@/Pages/Campaigns/Collateral';

/**
 * Ask a customer to buy the thing they actually chose.
 *
 * Every locked control said "Upgrade" and pointed at the monthly plans. A
 * setup-only customer has explicitly picked "one payment, nothing recurring"
 * and owes US$999 — sending them to a menu of subscriptions asks them to buy
 * the thing they did not want and hides the one they did. The $999 card is on
 * that page, but being on the page is not the same as being what was asked
 * for.
 */
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual('@inertiajs/react');

    return { ...actual, router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn() } };
});

beforeEach(() => vi.clearAllMocks());

describe('the locked-control call to action', () => {
    it('sends a setup-only customer to their own one-off payment', () => {
        render(<UnlockAction setupOnly label="Download" className="x" />);

        fireEvent.click(screen.getByRole('button'));

        expect(router.post).toHaveBeenCalledWith('/__route__/setup-fee.checkout');
    });

    it('names the price rather than saying upgrade', () => {
        render(<UnlockAction setupOnly label="Download" className="x" />);

        // "Upgrade" reads as a subscription to someone who bought a one-off.
        expect(screen.getByRole('button').textContent).toContain('US$999');
    });

    it('still sends a subscription customer to the plans', () => {
        render(<UnlockAction setupOnly={false} label="Download" className="x" />);

        const link = screen.getByRole('link');

        expect(link.getAttribute('href')).toContain('subscription.pricing');
        expect(link.textContent.toLowerCase()).toContain('upgrade');
    });
});
