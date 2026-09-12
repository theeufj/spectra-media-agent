import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';

const page = vi.hoisted(() => ({ url: '/dashboard', props: {} }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { on: () => () => {}, visit: vi.fn() },
}));

import OnboardingTour from '@/Components/OnboardingTour';

/**
 * The tour must survive a nav item the user cannot see.
 *
 * Sandbox is admin-only — routes/web.php puts the group behind ['auth',
 * 'admin'] — but its nav link was rendered for everyone, so a customer
 * clicking it got a 403 and the tour walked them there on purpose.
 *
 * Gating the link exposed the worse half. A step whose target was missing did
 * not get skipped: positionTooltip() called finish() on it, ending the tour. A
 * non-admin would have reached Sandbox seventh of nine and simply stopped,
 * never seeing "Ready to start? Click here to create your first campaign" —
 * the only step that asks them to do anything.
 */

const TARGETS = [
    'dashboard', 'campaigns', 'content', 'profile',
    'insights', 'strategy', 'sandbox', 'new-campaign',
];

/** Render the nav targets a given user would actually have. */
function mountNav(present) {
    document.body.innerHTML = present
        .map((t) => `<a data-tour="${t}" href="#">${t}</a>`)
        .join('');

    // jsdom gives every element zero dimensions, so isVisible() would reject
    // them all — getClientRects is what the component's own check falls back
    // to, and that is what a real rendered nav would satisfy.
    document.querySelectorAll('[data-tour]').forEach((el) => {
        el.getClientRects = () => [{ width: 100, height: 20 }];
        el.getBoundingClientRect = () => ({
            top: 0, bottom: 20, left: 0, right: 100, width: 100, height: 20,
        });
    });
}

describe('OnboardingTour step filtering', () => {
    beforeEach(() => {
        localStorage.clear?.();
        page.url = '/dashboard';
        page.props = {};
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('counts only the steps this user can be shown', () => {
        // A customer: every nav item except Sandbox.
        mountNav(TARGETS.filter((t) => t !== 'sandbox'));

        render(<OnboardingTour forceShow />);

        expect(screen.getByText(/Step 1 of 7/)).toBeInTheDocument();
    });

    it('shows all of them to an admin', () => {
        mountNav(TARGETS);

        render(<OnboardingTour forceShow />);

        expect(screen.getByText(/Step 1 of 8/)).toBeInTheDocument();
    });

    it('still reaches the final call to action without the admin-only step', () => {
        // The regression that mattered: the tour used to die at Sandbox.
        mountNav(TARGETS.filter((t) => t !== 'sandbox'));

        render(<OnboardingTour forceShow />);

        for (let i = 0; i < 6; i++) {
            act(() => {
                fireEvent.click(screen.getByText('Next'));
            });
        }

        expect(screen.getByText('Ready to start?')).toBeInTheDocument();
        expect(screen.getByText(/Step 7 of 7/)).toBeInTheDocument();
    });

    it('never narrates a step the user has no nav item for', () => {
        mountNav(TARGETS.filter((t) => t !== 'sandbox'));

        render(<OnboardingTour forceShow />);

        for (let i = 0; i < 6; i++) {
            act(() => {
                fireEvent.click(screen.getByText('Next'));
            });
        }

        expect(screen.queryByText('AI Sandbox')).not.toBeInTheDocument();
    });
});
