import React from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
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
    fireEvent.change(screen.getByLabelText('Entry source'), { target: { value: 'google' } });
    fireEvent.change(screen.getByLabelText('Campaign'), { target: { value: 'spectra_9' } });
    fireEvent.change(screen.getByLabelText('Entry destination'), { target: { value: 'google_ads_service' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(visit).toHaveBeenCalledWith('/admin/conversions', {
        funnel_from: '2026-11-20', funnel_to: '2026-11-22', funnel_source: 'google', funnel_campaign: 'spectra_9', funnel_entry_surface: 'google_ads_service',
    }, expect.objectContaining({ only: ['signup_funnel', 'errors'] }));
});
