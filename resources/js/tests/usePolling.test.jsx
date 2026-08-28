import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act } from '@testing-library/react';
import { usePolling } from '@/hooks/usePolling';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
import { fetchJson } from '@/utils/http';

// Tiny harness: exposes the hook's state through the DOM.
function Harness({ url, options }) {
    const { data, error, isPolling } = usePolling(url, options);

    return (
        <div>
            <span data-testid="data">{JSON.stringify(data)}</span>
            <span data-testid="error">{error ? 'error' : ''}</span>
            <span data-testid="polling">{isPolling ? 'yes' : 'no'}</span>
        </div>
    );
}

describe('usePolling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        fetchJson.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('fetches immediately and then on every interval', async () => {
        fetchJson.mockResolvedValue({ ok: true });

        render(<Harness url="/status" options={{ interval: 1000 }} />);

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(fetchJson).toHaveBeenCalledTimes(1);

        await act(() => vi.advanceTimersByTimeAsync(3000));
        expect(fetchJson).toHaveBeenCalledTimes(4);
    });

    it('stops polling once the until predicate is satisfied', async () => {
        fetchJson
            .mockResolvedValueOnce({ done: false })
            .mockResolvedValueOnce({ done: true })
            .mockResolvedValue({ done: true });

        const { getByTestId } = render(
            <Harness url="/status" options={{ interval: 1000, until: (d) => d.done }} />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByTestId('polling').textContent).toBe('yes');

        await act(() => vi.advanceTimersByTimeAsync(1000));
        expect(getByTestId('polling').textContent).toBe('no');
        const callsAtStop = fetchJson.mock.calls.length;

        await act(() => vi.advanceTimersByTimeAsync(5000));
        expect(fetchJson.mock.calls.length).toBe(callsAtStop);
    });

    it('skips ticks while a slow request is still in flight', async () => {
        let release;
        fetchJson.mockImplementation(() => new Promise((resolve) => { release = resolve; }));

        render(<Harness url="/status" options={{ interval: 1000 }} />);

        await act(() => vi.advanceTimersByTimeAsync(0));
        // Three intervals pass while the first request hangs.
        await act(() => vi.advanceTimersByTimeAsync(3000));
        expect(fetchJson).toHaveBeenCalledTimes(1);

        await act(async () => {
            release({ ok: true });
            await vi.advanceTimersByTimeAsync(1000);
        });
        expect(fetchJson).toHaveBeenCalledTimes(2);
    });

    it('does nothing when the url is null', async () => {
        const { getByTestId } = render(<Harness url={null} options={{ interval: 1000 }} />);

        await act(() => vi.advanceTimersByTimeAsync(3000));
        expect(fetchJson).not.toHaveBeenCalled();
        expect(getByTestId('polling').textContent).toBe('no');
    });

    it('stops fetching after unmount', async () => {
        fetchJson.mockResolvedValue({ ok: true });

        const { unmount } = render(<Harness url="/status" options={{ interval: 1000 }} />);
        await act(() => vi.advanceTimersByTimeAsync(0));
        unmount();

        const callsAtUnmount = fetchJson.mock.calls.length;
        await act(() => vi.advanceTimersByTimeAsync(5000));
        expect(fetchJson.mock.calls.length).toBe(callsAtUnmount);
    });

    it('surfaces fetch failures as error without stopping the poll', async () => {
        fetchJson
            .mockRejectedValueOnce(new Error('boom'))
            .mockResolvedValue({ ok: true });

        const { getByTestId } = render(<Harness url="/status" options={{ interval: 1000 }} />);

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByTestId('error').textContent).toBe('error');

        await act(() => vi.advanceTimersByTimeAsync(1000));
        expect(getByTestId('error').textContent).toBe('');
        expect(getByTestId('data').textContent).toContain('ok');
    });
});
