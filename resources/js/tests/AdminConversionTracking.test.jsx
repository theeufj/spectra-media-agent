import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import ConversionTracking from '@/Pages/Admin/ConversionTracking';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/Pages/Admin/SideNav', () => ({ default: () => null }));

afterEach(cleanup);

const counts = (total, changes = {}) => ({
    registrations_or_payments: total, processed: 0, processed_with_warnings: 0, provider_accepted: 0,
    processing: 0, upload_failed: 0, processing_failed: 0, legacy_unverified: 0, processing_unknown: 0,
    no_click_identifier: 0, ...changes,
});

describe('admin conversion delivery', () => {
    it('keeps setup payments distinct from subscriptions and shows processing without implying ad attribution', () => {
        render(<ConversionTracking aw_id="AW-123" actions={[]} attribution={{}} signups_7d={0} signups_30d={0}
            event_totals={{}} recent_events={[]} delivery={{ days: 30, reported_in_google_ads: 'not_verified', events: {
                signup: counts(4, { processed: 1 }), paid_subscription: counts(3, { provider_accepted: 1 }),
                paid_setup: counts(2, { upload_failed: 1, processing: 1 }),
            } }} />);

        expect(screen.getByText('Registrations: 4')).toBeVisible();
        expect(screen.getByText('First subscription payments: 3')).toBeVisible();
        const setup = screen.getByText('One-time setup payments: 2').parentElement;
        expect(within(setup).getByText('Processed: 0 · Awaiting processing: 1')).toBeVisible();
        expect(within(setup).getByText('Failed: 1 · Unverified: 0 · No click identifier: 0')).toBeVisible();
        expect(screen.getByText(/A processed event still needs to appear in Google Ads reporting before ad attribution is confirmed/)).toBeVisible();
        expect(screen.queryByText('First subscription payments: 2')).toBeNull();
    });
});
