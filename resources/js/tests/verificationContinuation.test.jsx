import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }) => <span>{children}</span>,
    router: { visit: vi.fn() },
    useForm: () => ({ post: vi.fn(), processing: false }),
}));
vi.mock('@/Layouts/GuestLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/Marketing/Hero', () => ({ brandTint: () => '#eee' }));
vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));

import { router } from '@inertiajs/react';
import { fetchJson } from '@/utils/http';
import VerifyEmail from '@/Pages/Auth/VerifyEmail';

beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    vi.stubGlobal('route', name => '/' + name);
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('email verification continuation', () => {
    it('waits for server confirmation, then leaves the old tab and stops polling', async () => {
        fetchJson.mockResolvedValueOnce({ verified: false }).mockResolvedValue({ verified: true });
        render(<VerifyEmail auth={{ user: { email: 'signup@example.test' } }} />);
        expect(screen.getByText('signup@example.test')).toBeTruthy();

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(router.visit).not.toHaveBeenCalled();
        await act(() => vi.advanceTimersByTimeAsync(5000));
        expect(router.visit).toHaveBeenCalledExactlyOnceWith('/dashboard', { replace: true });
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(fetchJson).toHaveBeenCalledTimes(2);
    });

    it('does not treat a failed status check as successful verification', async () => {
        fetchJson.mockRejectedValue(new Error('Network unavailable'));
        render(<VerifyEmail />);
        await act(() => vi.advanceTimersByTimeAsync(10000));
        expect(router.visit).not.toHaveBeenCalled();
    });
});
