import React from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import SignupFunnel from '@/Pages/Admin/SignupFunnel';

afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); });

it('keeps an empty cohort distinct from historical zero visits and requests the filtered report', () => {
    const visit = vi.spyOn(router, 'get').mockImplementation(() => {});
    vi.stubGlobal('route', () => '/admin/conversions');
    render(<SignupFunnel report={{
        filters: { funnel_from: '2026-11-20', funnel_to: '2026-11-22' },
        campaigns: ['spectra_9'],
        as_of: '2026-11-22T03:00:00+00:00',
        first_observed_at: null,
        journeys: 0,
    }} />);
    expect(screen.getByRole('status').textContent).toMatch(/No observed journeys match/);
    expect(screen.getByText(/Earlier visits cannot be reconstructed/)).toBeTruthy();
    expect(screen.getByText(/paid ad attribution are unverified for this cohort/)).toBeTruthy();
    fireEvent.click(screen.getByText(/Filter sessions/));
    fireEvent.change(screen.getByLabelText('Entry source'), { target: { value: 'google' } });
    fireEvent.change(screen.getByLabelText('Campaign'), { target: { value: 'spectra_9' } });
    fireEvent.change(screen.getByLabelText('Entry destination'), { target: { value: 'google_ads_service' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(visit).toHaveBeenCalledWith('/admin/conversions', {
        funnel_from: '2026-11-20', funnel_to: '2026-11-22', funnel_source: 'google', funnel_campaign: 'spectra_9', funnel_entry_surface: 'google_ads_service',
    }, expect.objectContaining({ only: ['signup_funnel', 'errors'] }));
});

it('shows the ordered preview path, registrations outside it and a filtered direct-entry link', () => {
    vi.stubGlobal('route', (name, filters) => '/admin/conversions?' + new URLSearchParams(filters).toString());
    const stageNames = ['landing_view', 'landing_visible', 'signup_cta_clicked', 'preview_started', 'preview_submitted', 'preview_succeeded', 'save_clicked', 'signup_viewed', 'signup_started', 'signup_submitted', 'signup_completed', 'signup_saved_draft', 'email_verified', 'paid_subscription'];
    const steps = Object.fromEntries(stageNames.map(key => [key, { reached: 0, both: 0, missing: 0, baseline_count: 0, dropoff_percent: null }]));
    steps.signup_completed.reached = 2;
    steps.paid_subscription.both = 1;
    render(<SignupFunnel report={{
        filters: { funnel_from: '2026-11-20', funnel_to: '2026-11-22', funnel_source: 'google' },
        campaigns: [],
        as_of: '2026-11-25T03:00:00+00:00',
        first_observed_at: '2026-11-20T05:00:00+00:00',
        journeys: 4, failures: [], steps,
        preview_path: {
            landing_visible: { reached: 4, previous: null, missing: null, dropoff_percent: null },
            preview_succeeded: { reached: 2, previous: 4, missing: 2, dropoff_percent: 50 },
            save_clicked: { reached: 1, previous: 2, missing: 1, dropoff_percent: 50 },
            signup_completed: { reached: 1, previous: 1, missing: 0, dropoff_percent: 0 },
            paid_subscription: { reached: 0, previous: 1, missing: 1, dropoff_percent: 100 },
        },
        direct_entry: { journeys: 1, registrations: 1, paid: 1 },
        other_paths: { registrations: 1, paid: 1 },
    }} />);
    const table = within(screen.getByRole('table', { name: 'Ad preview conversion funnel' }));
    expect(screen.getByText(/had the largest drop by session count/)).toHaveTextContent('Ad preview displayed');
    expect(table.getByRole('row', { name: /Save ad draft clicked/ })).toHaveTextContent('1 (50%)');
    expect(table.getByRole('row', { name: /First paid subscription/ })).toHaveTextContent('1 (100%)');
    expect(screen.getByText(/Outside the observed preview-and-save sequence/, { selector: 'p' })).toHaveTextContent('1 completed registration and 1 first payment');
    expect(screen.getByRole('link', { name: 'View direct-entry cohort' })).toHaveAttribute('href', expect.stringContaining('funnel_entry_surface=register'));
    fireEvent.click(screen.getByText(/Filter sessions/));
    expect(screen.getByRole('option', { name: 'Pricing' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Blog and guides' })).toBeInTheDocument();
});
