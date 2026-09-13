import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act } from '@testing-library/react';
import SetupProgressNav from '@/Components/SetupProgressNav';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
/*
   The mock has to be as strict as the real thing.

   Inertia's <Link> runs mergeDataIntoQueryString(method, href, ...) inside a
   useMemo, and that calls href.toString() — so a null href is a TypeError that
   the error boundary turns into "Something went wrong" for the whole page. A
   forgiving `<a href={null}>` mock renders it happily, which is how a null
   action_url took down the dashboard of a customer who had just paid US$999
   while this suite stayed green.
*/
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => {
        href.toString();

        return <a href={href} {...props}>{children}</a>;
    },
}));
import { fetchJson } from '@/utils/http';

const step = (key, title, status, extra = {}) => ({
    key,
    title,
    status,
    completed: status === 'completed',
    action_url: `/${key}`,
    description: `${title} description`,
    ...extra,
});

const payload = (steps, overrides = {}) => ({
    steps,
    progress: Math.round((steps.filter(s => s.completed).length / steps.length) * 100),
    completed_steps: steps.filter(s => s.completed).length,
    total_steps: steps.length,
    is_working: steps.some(s => s.status === 'in_progress'),
    ...overrides,
});

describe('SetupProgressNav', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        fetchJson.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the checklist with progress counts', async () => {
        fetchJson.mockResolvedValue(payload([
            step('site_scan', 'Site scan', 'completed'),
            step('first_campaign', 'First campaign', 'pending'),
        ]));

        const { getByText } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(getByText('Get Started')).toBeInTheDocument();
        expect(getByText('1/2 complete')).toBeInTheDocument();
        expect(getByText('Site scan')).toBeInTheDocument();
        expect(getByText('First campaign')).toBeInTheDocument();
    });

    it('narrates the step currently in progress', async () => {
        fetchJson.mockResolvedValue(payload([
            step('site_scan', 'Site scan', 'in_progress'),
            step('first_campaign', 'First campaign', 'pending'),
        ]));

        const { getByText, getByLabelText } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(getByLabelText('in progress')).toBeInTheDocument();
        expect(getByText('Site scan description')).toBeInTheDocument();
    });

    it('prefers the failed step\'s explanation over the in-progress one', async () => {
        fetchJson.mockResolvedValue(payload([
            step('site_scan', 'Site scan', 'failed'),
            step('first_campaign', 'First campaign', 'in_progress'),
        ]));

        const { getByText } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(getByText('Site scan description')).toBeInTheDocument();
    });

    it('marks the step the server says is current, not its own guess', async () => {
        // The server sends current_step; this component used to recompute it as
        // "first incomplete step that is not in progress", so whenever anything
        // was actually running it highlighted the step *after* the work in
        // flight — on the one card whose job is to say what happens next.
        const steps = [
            step('site_scan', 'Site scan', 'in_progress'),
            step('first_campaign', 'First campaign', 'pending'),
        ];

        fetchJson.mockResolvedValue(payload(steps, { current_step: steps[0] }));

        const { getByText } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(getByText('Site scan').closest('a')).toHaveAttribute('aria-current', 'step');
        expect(getByText('First campaign').closest('a')).not.toHaveAttribute('aria-current');
    });

    it('falls back to the first incomplete step when the server sends none', async () => {
        fetchJson.mockResolvedValue(payload([
            step('site_scan', 'Site scan', 'completed'),
            step('first_campaign', 'First campaign', 'pending'),
        ]));

        const { getByText } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(getByText('First campaign').closest('a')).toHaveAttribute('aria-current', 'step');
    });

    it('does not tick a later step while an earlier one has failed', async () => {
        // The strip showed "Scan your website" in warning red and "Deploy your
        // ads" ticked green at the same time — contradictory to anyone reading
        // left to right. The steps are a dependency chain.
        fetchJson.mockResolvedValue(payload([
            step('site_scan', 'Site scan', 'failed'),
            step('first_campaign', 'First campaign', 'pending'),
            step('deployed', 'Deploy your ads', 'completed'),
        ]));

        const { getByText } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        const deployed = getByText('Deploy your ads').closest('a');
        expect(deployed.className).not.toMatch(/bg-green-100/);
        expect(deployed.className).toMatch(/text-gray-400/);
    });

    it('still ticks completed steps when nothing has failed', async () => {
        fetchJson.mockResolvedValue(payload([
            step('site_scan', 'Site scan', 'completed'),
            step('first_campaign', 'First campaign', 'pending'),
        ]));

        const { getByText } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(getByText('Site scan').closest('a').className).toMatch(/bg-green-100/);
    });

    it('disappears once setup is complete', async () => {
        fetchJson.mockResolvedValue(payload(
            [step('site_scan', 'Site scan', 'completed')],
            { progress: 100 }
        ));

        const { container } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing rather than a broken card when the fetch fails', async () => {
        fetchJson.mockRejectedValue(new Error('500'));

        const { container } = render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(container).toBeEmptyDOMElement();
    });

    it('keeps polling while work is in flight and stops when it settles', async () => {
        fetchJson
            .mockResolvedValueOnce(payload([step('site_scan', 'Site scan', 'in_progress')]))
            .mockResolvedValue(payload([
                step('site_scan', 'Site scan', 'completed'),
                step('first_campaign', 'First campaign', 'pending'),
            ], { is_working: false }));

        render(<SetupProgressNav />);
        await act(() => vi.advanceTimersByTimeAsync(0));
        await act(() => vi.advanceTimersByTimeAsync(8000));

        const callsAtSettle = fetchJson.mock.calls.length;
        await act(() => vi.advanceTimersByTimeAsync(30000));
        expect(fetchJson.mock.calls.length).toBe(callsAtSettle);
    });

    it('does not render a link for a step with nowhere to go', async () => {
        /*
           The one-time setup journey is mostly work we do: "we build your
           account", "the keys are yours". Those steps carry action_url null
           because there is no page for the customer to visit, and the managed
           journey has the same shape while a step is still ours to finish.
        */
        fetchJson.mockResolvedValue(payload([
            step('payment', 'Pay your setup fee', 'completed'),
            step('review_ads', 'Review and create your ads', 'in_progress', { action_url: null }),
            step('handover', 'The keys are yours', 'pending', { action_url: null }),
        ]));

        let container;
        await act(async () => {
            ({ container } = render(<SetupProgressNav />));
        });

        // Rendered, and rendered as text rather than as a link to nowhere.
        expect(container.textContent).toContain('The keys are yours');
        expect(container.querySelectorAll('a')).toHaveLength(1);
        expect(container.querySelector('a').getAttribute('href')).toBe('/payment');
    });
});
