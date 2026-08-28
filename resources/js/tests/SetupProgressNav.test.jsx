import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act } from '@testing-library/react';
import SetupProgressNav from '@/Components/SetupProgressNav';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
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
});
