import { act, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import VerifiedPurchaseTracking from '@/Components/VerifiedPurchaseTracking';

const { page, polling, invoke } = vi.hoisted(() => ({ page: { props: {} }, polling: vi.fn(), invoke: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: (url, options) => { polling(url, options); return { data: null }; } }));
vi.mock('@/utils/verifiedPurchases', () => ({ invokeVerifiedPurchases: invoke }));

beforeEach(() => {
    vi.useFakeTimers();
    polling.mockReset();
    invoke.mockReset();
    page.props = { purchaseTracking: { installed: true, pending: [], pending_url: '/purchase-tracking/pending', watch_for_payment: true } };
});
afterEach(() => vi.useRealTimers());

it('waits briefly for the signed payment webhook after checkout, then stops polling', async () => {
    render(<VerifiedPurchaseTracking />);
    expect(polling.mock.calls.at(-1)[1].enabled).toBe(true);
    await act(() => vi.advanceTimersByTimeAsync(60000));
    expect(polling.mock.calls.at(-1)[1].enabled).toBe(false);
});

it('stops watching when leaving the checkout return instead of leaking an indefinite poll', () => {
    const { rerender } = render(<VerifiedPurchaseTracking />);
    page.props.purchaseTracking = { ...page.props.purchaseTracking, watch_for_payment: false };
    rerender(<VerifiedPurchaseTracking />);
    expect(polling.mock.calls.at(-1)[1].enabled).toBe(false);
});
