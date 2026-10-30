import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, within } from '@testing-library/react';
import GoogleApiConnect from '@/Pages/Settings/GoogleApiConnect';

const { post, page } = vi.hoisted(() => ({ post: vi.fn(), page: { props: {} } }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { post }, usePage: () => page }));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <main>{children}</main> }));

beforeEach(() => { post.mockReset(); page.props = {}; });

describe('platform tracking authorization', () => {
    it('uses the dedicated tracking authorization route and prevents duplicate pending requests', () => {
        const view = render(<GoogleApiConnect canAuthorizeTracking platformTracking={{ account_id: '123456' }} />);
        const section = within(view.getByRole('region', { name: 'Platform tracking authorization' }));
        expect(section.getByText(/Google Ads credentials stay unchanged/)).toBeVisible();
        fireEvent.click(section.getByRole('button', { name: 'Authorize platform tracking' }));
        expect(post).toHaveBeenCalledExactlyOnceWith(route('google-api.tracking-authorize'), {}, expect.any(Object));
        act(() => post.mock.calls[0][2].onStart());
        const pending = section.getByRole('button', { name: 'Opening Google…' });
        expect(pending).toBeDisabled();
        fireEvent.click(pending);
        expect(post).toHaveBeenCalledTimes(1);
        act(() => post.mock.calls[0][2].onFinish());
        expect(section.getByRole('button', { name: 'Authorize platform tracking' })).toBeEnabled();
    });

    it('does not offer a writable tracking authorization action to read-only support', () => {
        const view = render(<GoogleApiConnect canAuthorizeTracking={false} />);
        const button = view.getByRole('button', { name: 'Authorize platform tracking' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(post).not.toHaveBeenCalled();
        expect(view.getByText('A full platform administrator must authorize this connection.')).toBeVisible();
    });

    it('shows the verified platform identity independently of the legacy API connection and exposes callback errors', () => {
        page.props = { errors: { oauth: 'The existing credential was kept.' } };
        const view = render(<GoogleApiConnect canAuthorizeTracking connection={null}
            platformTracking={{ account_id: '123456', ownership_checks_authorized: true, email: 'tracking@example.com' }} />);
        expect(view.getByText('Authorized as tracking@example.com')).toBeVisible();
        expect(view.getByRole('heading', { name: 'API verification connection' })).toBeVisible();
        expect(view.queryByText('Google APIs connected')).toBeNull();
        expect(view.getByRole('alert')).toHaveTextContent('The existing credential was kept.');
    });
});
