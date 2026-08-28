import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act, fireEvent } from '@testing-library/react';
import BrandExtractionStatus from '@/Components/BrandExtractionStatus';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));
import { fetchJson } from '@/utils/http';
import { router } from '@inertiajs/react';

describe('BrandExtractionStatus', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        fetchJson.mockReset();
        router.reload.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('narrates the wait while the extraction runs', async () => {
        fetchJson.mockResolvedValue({ exists: false, failed: false });

        const { getByText } = render(
            <BrandExtractionStatus watching baselineUpdatedAt={null} />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByText('Analysing your website…')).toBeInTheDocument();
    });

    it('announces completion, reloads the guideline, and settles', async () => {
        fetchJson
            .mockResolvedValueOnce({ exists: false, failed: false })
            .mockResolvedValue({ exists: true, failed: false, updated_at: '2026-08-29T10:00:00+00:00' });
        const onSettled = vi.fn();

        const { getByText } = render(
            <BrandExtractionStatus watching baselineUpdatedAt={null} onSettled={onSettled} />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        await act(() => vi.advanceTimersByTimeAsync(5000));

        expect(getByText('Your brand profile is ready')).toBeInTheDocument();
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['brandGuideline'] })
        );
        expect(onSettled).toHaveBeenCalledTimes(1);
    });

    it('only treats a guideline fresher than the baseline as done', async () => {
        // Same stamp as the baseline: this is the OLD guideline, keep waiting.
        fetchJson.mockResolvedValue({ exists: true, failed: false, updated_at: '2026-08-29T09:00:00+00:00' });

        const { getByText } = render(
            <BrandExtractionStatus watching baselineUpdatedAt="2026-08-29T09:00:00+00:00" />
        );

        await act(() => vi.advanceTimersByTimeAsync(5000));
        expect(getByText('Analysing your website…')).toBeInTheDocument();
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('surfaces the server-recorded failure reason', async () => {
        fetchJson.mockResolvedValue({
            exists: false,
            failed: true,
            failure_reason: 'Your site\'s security service blocked our scanner.',
        });
        const onSettled = vi.fn();

        const { getByText } = render(
            <BrandExtractionStatus watching baselineUpdatedAt={null} onSettled={onSettled} />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByText('We couldn\'t finish analysing your website')).toBeInTheDocument();
        expect(getByText('Your site\'s security service blocked our scanner.')).toBeInTheDocument();
        expect(onSettled).toHaveBeenCalledTimes(1);
    });

    it('can be dismissed after failing', async () => {
        fetchJson.mockResolvedValue({ exists: false, failed: true, failure_reason: 'nope' });

        const { getByLabelText, queryByText } = render(
            <BrandExtractionStatus watching baselineUpdatedAt={null} />
        );

        await act(() => vi.advanceTimersByTimeAsync(0));
        fireEvent.click(getByLabelText('Dismiss'));
        expect(queryByText('We couldn\'t finish analysing your website')).toBeNull();
    });
});
