import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act } from '@testing-library/react';
import { useJobWatch } from '@/hooks/useJobWatch';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
import { fetchJson } from '@/utils/http';

function Harness({ enabled, options }) {
    const { phase } = useJobWatch('/job-status', { enabled, ...options });

    return <span data-testid="phase">{phase}</span>;
}

describe('useJobWatch', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        fetchJson.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('is idle while disabled and never polls', async () => {
        const { getByTestId } = render(
            <Harness enabled={false} options={{ isDone: () => true }} />
        );

        await act(() => vi.advanceTimersByTimeAsync(10000));
        expect(getByTestId('phase').textContent).toBe('idle');
        expect(fetchJson).not.toHaveBeenCalled();
    });

    it('watches, then lands on done and fires onDone exactly once', async () => {
        fetchJson
            .mockResolvedValueOnce({ finished: false })
            .mockResolvedValue({ finished: true });
        const onDone = vi.fn();

        const { getByTestId } = render(
            <Harness enabled options={{ interval: 1000, isDone: (d) => d.finished, onDone }} />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByTestId('phase').textContent).toBe('watching');

        await act(() => vi.advanceTimersByTimeAsync(1000));
        expect(getByTestId('phase').textContent).toBe('done');
        expect(onDone).toHaveBeenCalledTimes(1);

        // Terminal: no more polling, no second onDone.
        const calls = fetchJson.mock.calls.length;
        await act(() => vi.advanceTimersByTimeAsync(5000));
        expect(fetchJson.mock.calls.length).toBe(calls);
        expect(onDone).toHaveBeenCalledTimes(1);
    });

    it('prefers failed over done and passes the payload to onFailed', async () => {
        fetchJson.mockResolvedValue({ finished: true, failed: true, reason: 'blocked' });
        const onDone = vi.fn();
        const onFailed = vi.fn();

        const { getByTestId } = render(
            <Harness
                enabled
                options={{
                    interval: 1000,
                    isDone: (d) => d.finished,
                    isFailed: (d) => d.failed,
                    onDone,
                    onFailed,
                }}
            />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByTestId('phase').textContent).toBe('failed');
        expect(onFailed).toHaveBeenCalledWith(expect.objectContaining({ reason: 'blocked' }));
        expect(onDone).not.toHaveBeenCalled();
    });

    it('gives up with timeout once the deadline passes', async () => {
        fetchJson.mockResolvedValue({ finished: false });

        const { getByTestId } = render(
            <Harness enabled options={{ interval: 1000, timeoutMs: 4500, isDone: (d) => d.finished }} />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        await act(() => vi.advanceTimersByTimeAsync(6000));
        expect(getByTestId('phase').textContent).toBe('timeout');
    });

    it('re-arms for a fresh watch when enabled goes false then true again', async () => {
        fetchJson.mockResolvedValue({ finished: true });

        const { getByTestId, rerender } = render(
            <Harness enabled options={{ interval: 1000, isDone: (d) => d.finished }} />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByTestId('phase').textContent).toBe('done');

        rerender(<Harness enabled={false} options={{ interval: 1000, isDone: (d) => d.finished }} />);
        // Terminal phase survives being disabled — the banner should linger.
        expect(getByTestId('phase').textContent).toBe('done');

        fetchJson.mockReset();
        fetchJson.mockResolvedValue({ finished: false });
        rerender(<Harness enabled options={{ interval: 1000, isDone: (d) => d.finished }} />);

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByTestId('phase').textContent).toBe('watching');
    });
});
