import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, within } from '@testing-library/react';
import GTMSetupPage from '@/Pages/Customers/GTM/Setup';

const { routerPost, routerReload, formPost, formState, page, poll, polling } = vi.hoisted(() => ({ routerPost: vi.fn(), routerReload: vi.fn(), formPost: vi.fn(), formState: { errors: {}, processing: false }, page: { props: {} }, poll: { data: null, error: null }, polling: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }) => <a href={href}>{children}</a>,
    router: { post: routerPost, reload: routerReload },
    usePage: () => page,
    useForm: initial => {
        const [data, setData] = React.useState(initial);
        return { data, setData: (key, value) => setData(previous => ({ ...previous, [key]: value })),
            processing: formState.processing, errors: formState.errors,
            post: (url, options) => formPost(url, data, options) };
    },
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: (...args) => { polling(...args); return poll; } }));

const identity = { email: 'owner@example.com', subject: 'google-owner', verified_at: '2026-10-31T00:00:00Z' };
const customer = { id: 7, uuid: 'customer-seven', name: 'Example business', service_type: 'setup_only', website: 'https://example.com',
    google_ads_customer_id: '1234567890', setup_handover: { google_identity: identity } };
const props = { auth: { user: {} }, customer, snippet: null, existingTags: null,
    trackingOwnership: { status: 'connection_required', connected: false, platform_email: 'tracking@sitetospend.com' } };
const connected = { status: 'connected', connected: true, email: identity.email, account_id: '123456', container_id: '987654',
    public_id: 'GTM-OWNED', platform_email: 'tracking@sitetospend.com', verified_at: '2026-10-31T00:00:00Z' };

beforeEach(() => {
    routerPost.mockReset(); routerReload.mockReset(); formPost.mockReset(); formState.errors = {}; formState.processing = false; page.props = {};
    poll.data = null; poll.error = null; polling.mockReset();
});

describe('customer-controlled setup handover', () => {
    it('refreshes changed background access evidence once and displays the new server props', () => {
        const view = render(<GTMSetupPage {...props} handoverRevision="before" />);
        expect(polling).toHaveBeenCalledWith(route('customers.gtm.status', { customer: customer.uuid }), expect.objectContaining({ interval: 10000, immediate: false, enabled: true }));
        poll.data = { handover_revision: 'before' };
        view.rerender(<GTMSetupPage {...props} handoverRevision="before" />);
        expect(routerReload).not.toHaveBeenCalled();
        poll.data = { handover_revision: 'after' };
        view.rerender(<GTMSetupPage {...props} handoverRevision="before" />);
        view.rerender(<GTMSetupPage {...props} handoverRevision="before" />);
        expect(routerReload).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({
            only: ['customer', 'snippet', 'trackingOwnership', 'handoverRevision', 'goalSetupUrl'], preserveScroll: true,
        }));
        act(() => routerReload.mock.calls[0][0].onFinish());
        const access = { status: 'accepted', email: identity.email, subject: identity.subject, account_id: customer.google_ads_customer_id };
        view.rerender(<GTMSetupPage {...props} handoverRevision="after" customer={{ ...customer, setup_handover: { google_identity: identity, ads_access: access } }} goalSetupUrl="/new-goal" />);
        expect(view.getByText(/Google confirms that owner@example.com/)).toBeVisible();
        expect(view.getByRole('link', { name: 'Review your conversion goal' })).toHaveAttribute('href', '/new-goal');
        expect(routerReload).toHaveBeenCalledTimes(1);
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('offers a retry after a status outage and restarts polling even when the saved revision is unchanged', () => {
        poll.error = new Error('Offline');
        const view = render(<GTMSetupPage {...props} handoverRevision="unchanged" />);
        expect(view.getByRole('alert')).toHaveTextContent('last confirmed details');
        const firstKey = polling.mock.calls.at(-1)[1].restartKey;
        fireEvent.click(view.getByRole('button', { name: 'Refresh account status' }));
        act(() => routerReload.mock.calls[0][0].onFinish());
        expect(polling.mock.calls.at(-1)[1].restartKey).not.toBe(firstKey);
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('requires identity confirmation before ownership selection and never offers shared-container provisioning', () => {
        const { getByRole, queryByRole, getByText } = render(<GTMSetupPage {...props} customer={{ ...customer, setup_handover: {} }} />);
        expect(getByRole('button', { name: 'Check Google Ads access' })).toBeDisabled();
        expect(getByRole('button', { name: 'Verify and use this container' })).toBeDisabled();
        expect(queryByRole('button', { name: 'Set up tracking' })).toBeNull();
        expect(getByText(/does not connect an existing ads account/)).toBeVisible();
        fireEvent.click(getByRole('button', { name: 'Continue with Google' }));
        expect(routerPost).toHaveBeenCalledExactlyOnceWith(route('customers.setup.google-identity', { customer: customer.uuid }), {}, expect.any(Object));
        expect(formPost).not.toHaveBeenCalled();
    });

    it.each([
        ['approval_pending', 'Google is waiting for an existing administrator'],
        ['invitation_pending', 'Accept it using the Google account shown above'],
        ['error', 'We could not verify access with Google'],
        ['unverified', 'We have not yet confirmed'],
    ])('distinguishes %s access from an accepted invitation', (status, text) => {
        const view = render(<GTMSetupPage {...props} customer={{ ...customer, setup_handover: { google_identity: identity, ads_access: { status } } }} />);
        const access = within(view.getByRole('region', { name: 'Your Google Ads access' }));
        expect(access.getByText(new RegExp(text))).toBeVisible();
        expect(access.queryByText(/Google confirms/)).toBeNull();
        fireEvent.click(access.getByRole('button', { name: 'Check Google Ads access' }));
        expect(routerPost).toHaveBeenCalledExactlyOnceWith(route('customers.setup.verify-access', { customer: customer.uuid }), {}, expect.any(Object));
    });

    it('only confirms Ads access for the verified Google identity and this child account', () => {
        const access = { status: 'accepted', email: identity.email, subject: identity.subject, account_id: customer.google_ads_customer_id };
        const { getByText, queryByText, rerender } = render(<GTMSetupPage {...props} customer={{ ...customer, setup_handover: { google_identity: identity, ads_access: access } }} />);
        expect(getByText(/Google confirms that owner@example.com has administrator access/)).toBeVisible();
        rerender(<GTMSetupPage {...props} customer={{ ...customer, setup_handover: { google_identity: identity, ads_access: { ...access, email: 'old-owner@example.com' } } }} />);
        expect(queryByText(/Google confirms/)).toBeNull();
        rerender(<GTMSetupPage {...props} customer={{ ...customer, setup_handover: { google_identity: identity, ads_access: { ...access, account_id: '0000000001' } } }} />);
        expect(queryByText(/Google confirms/)).toBeNull();
        rerender(<GTMSetupPage {...props} customer={{ ...customer, setup_handover: { google_identity: identity, ads_access: { ...access, subject: 'old-subject' } } }} />);
        expect(queryByText(/Google confirms/)).toBeNull();
    });

    it('shows an identity callback failure and the later confirmed sign-in message', () => {
        page.props = { errors: { google_identity: 'Your Google identity check expired. Start again.' } };
        const { getByRole, rerender, queryByText } = render(<GTMSetupPage {...props} />);
        expect(getByRole('alert')).toHaveTextContent('Your Google identity check expired. Start again.');
        page.props = { flash: { type: 'success', message: 'Google identity confirmed. Continue with account access and tracking setup.' } };
        rerender(<GTMSetupPage {...props} />);
        expect(getByRole('status')).toHaveTextContent('Google identity confirmed. Continue with account access and tracking setup.');
        expect(queryByText('Your Google identity check expired. Start again.')).toBeNull();
    });

    it('does not accept a removed customer member’s cached identity when the server requires verification again', () => {
        const view = render(<GTMSetupPage {...props} trackingOwnership={{ ...props.trackingOwnership, status: 'identity_required' }} />);
        expect(view.getByRole('button', { name: 'Continue with Google' })).toBeEnabled();
        expect(view.getByRole('button', { name: 'Check Google Ads access' })).toBeDisabled();
        expect(view.queryByText(/Google identity confirmed:/)).toBeNull();
    });

    it('requires explicit numeric account and container selection instead of a public GTM identifier', () => {
        const { getByLabelText, getByRole } = render(<GTMSetupPage {...props} />);
        fireEvent.change(getByLabelText('Tag Manager account number'), { target: { value: '123456' } });
        fireEvent.change(getByLabelText('Container number'), { target: { value: 'GTM-OWNED' } });
        expect(getByRole('button', { name: 'Verify and use this container' })).toBeDisabled();
        fireEvent.change(getByLabelText('Container number'), { target: { value: '987654' } });
        expect(formPost).not.toHaveBeenCalled();
        fireEvent.click(getByRole('button', { name: 'Verify and use this container' }));
        expect(formPost).toHaveBeenCalledExactlyOnceWith(route('customers.gtm.connect', { customer: customer.uuid }), {
            account_id: '123456', container_id: '987654',
        }, { preserveScroll: true });
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('does not reuse a shared platform container as proof of customer ownership', () => {
        const view = render(<GTMSetupPage {...props} customer={{ ...customer, gtm_container_id: 'GTM-SHARED', gtm_installed: true }}
            snippet={{ head: 'shared head snippet', body: 'shared body snippet' }}
            trackingOwnership={{ ...props.trackingOwnership, status: 'legacy_platform_container' }} />);
        expect(view.getByText(/It does not establish your ownership/)).toBeVisible();
        expect(view.queryByRole('button', { name: 'Check website tag' })).toBeNull();
        expect(view.queryByText('shared head snippet')).toBeNull();
        expect(view.getByText('A successful conversion test is not yet verified here.')).toBeVisible();
        expect(view.queryByRole('checkbox')).toBeNull();
    });

    it('reuses the same installed customer-owned container without claiming a conversion test passed', () => {
        const view = render(<GTMSetupPage {...props} customer={{ ...customer, gtm_container_id: 'GTM-OWNED', gtm_installed: true }}
            trackingOwnership={connected} existingTags={{ detected_container_id: 'GTM-OWNED' }}
            snippet={{ head: 'owned head snippet', body: 'owned body snippet' }} goalSetupUrl="/campaigns/campaign-one/conversion-goal" />);
        expect(view.getByText(/you do not reinstall the base website snippet for every campaign/)).toBeVisible();
        expect(view.getByText('Your selected website tag was found. A real conversion event still needs testing.')).toBeVisible();
        expect(view.getByText('A successful conversion test is not yet verified here.')).toBeVisible();
        expect(view.queryByText(/Conversion tracking is active/)).toBeNull();
        expect(view.getByRole('link', { name: 'Review your conversion goal' })).toHaveAttribute('href', '/campaigns/campaign-one/conversion-goal');
        expect(view.getByRole('link', { name: 'Open Tag Assistant' })).toHaveAttribute('href', 'https://tagassistant.google.com/');
        expect(view.getByText(/If you remove SiteToSpend’s access later/)).toBeVisible();
        fireEvent.click(view.getByRole('button', { name: 'Check website tag' }));
        expect(routerPost).toHaveBeenCalledExactlyOnceWith(route('customers.gtm.verify', customer.uuid), {}, expect.any(Object));
    });

    it('shows stale ownership checks and permission errors without approving handover', () => {
        formState.errors = { account_id: 'Your Google identity must administer this account.' };
        const view = render(<GTMSetupPage {...props} customer={{ ...customer, gtm_container_id: 'GTM-OWNED' }} trackingOwnership={{ ...connected, requires_recheck: true }} />);
        expect(view.getByText(/These permissions need a fresh check/)).toBeVisible();
        expect(view.getByRole('alert')).toHaveTextContent('Your Google identity must administer this account.');
        expect(view.getByLabelText('Tag Manager account number')).toHaveAttribute('aria-invalid', 'true');
        expect(view.queryByRole('button', { name: /complete handover|mark.*verified/i })).toBeNull();
    });

    it('preserves the previously selected customer container for a failed-permissions recheck', () => {
        const view = render(<GTMSetupPage {...props} customer={{ ...customer, gtm_container_id: 'GTM-OWNED' }}
            trackingOwnership={{ ...connected, status: 'access_unverified', connected: false, message: 'Google access could not be checked. Verify this container again.' }} />);
        expect(view.getByLabelText('Tag Manager account number')).toHaveValue('123456');
        expect(view.getByLabelText('Container number')).toHaveValue('987654');
        expect(view.getByText('Google access could not be checked. Verify this container again.')).toBeVisible();
        expect(view.queryByRole('button', { name: 'Check website tag' })).toBeNull();
    });

    it('does not invent the platform sharing email when tracking access is not configured', () => {
        const view = render(<GTMSetupPage {...props} trackingOwnership={{ status: 'connection_required', connected: false }} />);
        expect(view.getByRole('alert')).toHaveTextContent('SiteToSpend’s tracking access is not configured yet');
        fireEvent.change(view.getByLabelText('Tag Manager account number'), { target: { value: '123456' } });
        fireEvent.change(view.getByLabelText('Container number'), { target: { value: '987654' } });
        expect(view.getByRole('button', { name: 'Verify and use this container' })).toBeDisabled();
        expect(formPost).not.toHaveBeenCalled();
    });

    it('preserves the existing managed-plan provisioning workflow', () => {
        const view = render(<GTMSetupPage {...props} customer={{ ...customer, service_type: 'managed' }} />);
        expect(view.queryByRole('region', { name: 'Your Google identity' })).toBeNull();
        expect(polling.mock.calls.at(-1)[1].enabled).toBe(false);
        fireEvent.click(view.getByRole('button', { name: 'Set up tracking' }));
        expect(routerPost).toHaveBeenCalledExactlyOnceWith(route('customers.gtm.provision', customer.uuid), {}, expect.any(Object));
    });
});
