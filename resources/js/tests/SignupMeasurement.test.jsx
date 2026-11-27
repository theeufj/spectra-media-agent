import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Register from '@/Pages/Auth/Register';
import { launchSandbox } from '@/Pages/Sandbox/launch';
import { initConversions, trackConversion } from '@/utils/conversions';

const requests = vi.hoisted(() => ({ register: vi.fn(), inertia: vi.fn(), record: vi.fn() }));
vi.mock('@/utils/http', () => ({ fetchJson: requests.record }));
vi.mock('@/Layouts/GuestLayout', () => ({ default: ({ children }) => <>{children}</> }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }) => <a href={href}>{children}</a>,
    router: { post: requests.inertia },
    usePage: () => ({ props: {} }),
    useForm: initial => {
        const [data, update] = React.useState(initial);
        return {
            data, errors: {}, processing: false,
            setData: (key, value) => update(previous => ({ ...previous, [key]: value })),
            post: () => requests.register(data),
        };
    },
}));

beforeEach(() => {
    vi.clearAllMocks();
    requests.record.mockResolvedValue({});
    vi.stubGlobal('route', (name, params) => '/' + name + (params ? '?' + new URLSearchParams(params) : ''));
    initConversions({});
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.history.replaceState(null, '', '/');
});

describe('signup measurement choices', () => {
    it('passes the same optional choice to password and Google signup after a preview', () => {
        const website = 'https://fixture.test';
        window.history.replaceState(null, '', '/register?demo_url=' + encodeURIComponent(website));
        render(<Register enabledPlatforms={[{ name: 'Google', slug: 'google' }]} />);
        const checkbox = screen.getByRole('checkbox', { name: /hashed version of my email address/i });
        const submit = screen.getByRole('button', { name: 'Create account' });
        const google = screen.getByRole('button', { name: 'Sign up with Google' });
        expect(checkbox).not.toBeChecked();
        expect(checkbox).not.toBeRequired();
        expect(requests.record).not.toHaveBeenCalled();

        for (const choice of [0, 1, 0]) {
            if (checkbox.checked !== Boolean(choice)) fireEvent.click(checkbox);
            fireEvent.click(google);
            expect(requests.inertia).toHaveBeenLastCalledWith('/register.google.redirect', {
                ad_measurement_consent: choice, ad_measurement_consent_version: 'signup-email-v1', demo_url: website,
            }, expect.objectContaining({ onError: expect.any(Function) }));
            fireEvent.submit(submit.closest('form'));
            expect(requests.register).toHaveBeenLastCalledWith(expect.objectContaining({
                ad_measurement_consent: choice, ad_measurement_consent_version: 'signup-email-v1',
            }));
        }
    });

    it('carries the request to save a preview independently of ad measurement consent', () => {
        render(<Register previewUrl="https://fixture.test" previewReference="browser-draft-reference" enabledPlatforms={[{ name: 'Google', slug: 'google' }]} />);
        expect(screen.getByRole('checkbox', { name: /hashed version of my email address/i })).not.toBeChecked();
        expect(screen.getByRole('link', { name: 'Continue without this preview' })).toHaveAttribute('href', '/register?without_preview=1');

        fireEvent.click(screen.getByRole('button', { name: 'Sign up with Google' }));
        expect(requests.inertia).toHaveBeenLastCalledWith('/register.google.redirect', {
            save_preview: true, preview_reference: 'browser-draft-reference', ad_measurement_consent: 0, ad_measurement_consent_version: 'signup-email-v1',
        }, expect.objectContaining({ onError: expect.any(Function) }));

        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }).closest('form'));
        expect(requests.register).toHaveBeenLastCalledWith(expect.objectContaining({ save_preview: true, ad_measurement_consent: 0 }));
    });

    it('records a completed preview locally even when no Google target exists', () => {
        const tag = vi.fn();
        vi.stubGlobal('gtag', tag);
        trackConversion('try_now');
        expect(requests.record).toHaveBeenCalledWith('/spectra/conversion', { method: 'POST', json: { event: 'try_now' } });
        expect(tag).not.toHaveBeenCalled();
    });

    it('records a sandbox only when its launch returns the results page', () => {
        launchSandbox();
        const { onSuccess } = requests.inertia.mock.calls[0][2];
        expect(requests.record).not.toHaveBeenCalled();
        onSuccess({ component: 'Auth/VerifyEmail' });
        expect(requests.record).not.toHaveBeenCalled();
        onSuccess({ component: 'Sandbox/Results' });
        expect(requests.record).toHaveBeenCalledWith('/spectra/conversion', { method: 'POST', json: { event: 'sandbox_launched' } });
    });
});
