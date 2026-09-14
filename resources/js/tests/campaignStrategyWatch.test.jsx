import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act } from '@testing-library/react';
import { useJobWatch } from '@/hooks/useJobWatch';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
import { fetchJson } from '@/utils/http';

/**
 * The campaign page's strategy watch, in the exact configuration
 * Pages/Campaigns/Show.jsx uses it.
 *
 * Reproduces a real onboarding failure. A customer landed on the campaign page
 * while strategy generation was still running, saw nothing change, gave up, and
 * signed up again from scratch — twice. Collateral generation only ever starts
 * from the sign-off button, so a page that never leaves its "generating" state
 * is an abandoned signup: his first attempt produced a strategy and then zero
 * ad copy and zero images, while the second, reached fresh from a notification
 * link after generation had finished, produced twelve images and two videos.
 *
 * The page polled with setInterval + raw fetch and handled failure with
 * console.error, so there was no difference the user could see between "still
 * working" and "this page is dead". These assertions are the two halves of that
 * distinction.
 */

function Harness({ enabled }) {
    const { phase, data } = useJobWatch('/api/campaigns/36', {
        enabled,
        interval: 10000,
        timeoutMs: 5 * 60 * 1000,
        isFailed: (d) => Boolean(d?.strategy_generation_error),
        isDone: (d) => Boolean(d?.strategies?.length) && !d?.is_generating_strategies,
    });

    return (
        <>
            <span data-testid="phase">{phase}</span>
            <span data-testid="strategies">{data?.strategies?.length ?? 0}</span>
        </>
    );
}

const GENERATING = { strategies: [], is_generating_strategies: true };
const READY = { strategies: [{ id: 740, platform: 'Google Ads' }], is_generating_strategies: false };

describe('campaign strategy watch', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        fetchJson.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('picks up strategies that arrive after the page has already loaded', async () => {
        // The customer's own timeline: generation ran for ~34s after the page
        // was open, then finished.
        fetchJson
            .mockResolvedValueOnce(GENERATING)
            .mockResolvedValueOnce(GENERATING)
            .mockResolvedValueOnce(GENERATING)
            .mockResolvedValue(READY);

        const { getByTestId } = render(<Harness enabled />);

        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(getByTestId('phase').textContent).toBe('watching');
        expect(getByTestId('strategies').textContent).toBe('0');

        await act(() => vi.advanceTimersByTimeAsync(40000));

        expect(getByTestId('phase').textContent).toBe('done');
        expect(getByTestId('strategies').textContent).toBe('1');
    });

    it('stops watching once strategies are in, rather than polling forever', async () => {
        fetchJson.mockResolvedValue(READY);

        render(<Harness enabled />);
        await act(() => vi.advanceTimersByTimeAsync(0));

        const settled = fetchJson.mock.calls.length;
        await act(() => vi.advanceTimersByTimeAsync(60000));

        expect(fetchJson.mock.calls.length).toBe(settled);
    });

    it('says it is disconnected when the endpoint stops answering', async () => {
        // An expired session or a deploy mid-generation. This is the case that
        // used to be a console.error and an indefinite spinner.
        fetchJson.mockRejectedValue(new Error('403 Forbidden'));

        const { getByTestId } = render(<Harness enabled />);

        await act(() => vi.advanceTimersByTimeAsync(50000));

        expect(getByTestId('phase').textContent).toBe('disconnected');
    });

    it('surfaces a server-reported generation failure as failed, not as slowness', async () => {
        fetchJson.mockResolvedValue({
            strategies: [],
            is_generating_strategies: false,
            strategy_generation_error: 'Gemini returned no usable strategy',
        });

        const { getByTestId } = render(<Harness enabled />);

        await act(() => vi.advanceTimersByTimeAsync(0));

        expect(getByTestId('phase').textContent).toBe('failed');
    });
});

/** Mirrors awaitingCollateral and its until() in Pages/Campaigns/Show.jsx. */
const items = (s) => (s.ad_copies_count || 0) + (s.image_collaterals_count || 0) + (s.video_collaterals_count || 0);
const awaitingCollateral = (strategies) => strategies.some(s => s.signed_off_at && items(s) === 0);
const collateralDone = (d) => (d?.strategies || []).every(s => ! s.signed_off_at || items(s) > 0);

describe('waiting for collateral on the strategy page', () => {
    it('keeps watching a signed-off strategy that has nothing attached yet', () => {
        /*
           isPolling covered strategy generation only, so it switched off the
           moment strategies existed — which is the moment collateral generation
           starts. The card underneath then sat on "Generating your collateral…"
           until the customer refreshed, on the screen they had just been told
           to wait on.
        */
        expect(awaitingCollateral([{ signed_off_at: '2026-09-14', ad_copies_count: 0, image_collaterals_count: 0 }])).toBe(true);
    });

    it('does not watch a strategy nobody has signed off', () => {
        // Nothing is coming for it, so polling would never end.
        expect(awaitingCollateral([{ signed_off_at: null, image_collaterals_count: 0 }])).toBe(false);
    });

    it('stops as soon as the collateral lands', () => {
        expect(collateralDone({ strategies: [{ signed_off_at: '2026-09-14', image_collaterals_count: 9 }] })).toBe(true);
        expect(collateralDone({ strategies: [{ signed_off_at: '2026-09-14', image_collaterals_count: 0 }] })).toBe(false);
    });

    it('counts ad copy on its own as collateral having arrived', () => {
        // Ad copy generates before the images, and the card has something real
        // to show the moment it does.
        expect(collateralDone({ strategies: [{ signed_off_at: '2026-09-14', ad_copies_count: 1 }] })).toBe(true);
    });
});
