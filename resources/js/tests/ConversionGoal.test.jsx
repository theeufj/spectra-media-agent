import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import ConversionGoal from '@/Pages/Campaigns/ConversionGoal';
import { HandoverChecklist } from '@/Components/SetupJourney';

const { formState, postMock, polling, pollingMock, visitMock } = vi.hoisted(() => ({
    formState: { errors: {}, processing: false }, postMock: vi.fn(), visitMock: vi.fn(),
    polling: { data: null, error: null }, pollingMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
    router: { reload: vi.fn(), visit: visitMock },
    useForm: initial => {
        const [data, setData] = React.useState(initial);
        return {
            data, errors: formState.errors, processing: formState.processing,
            setData: (key, value) => setData(current => typeof key === 'object' ? key : { ...current, [key]: value }),
            post: (url, options) => postMock(url, data, options),
        };
    },
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: (url, options) => { pollingMock(url, options); return polling; } }));

const props = {
    auth: { user: {} }, campaign: { uuid: 'campaign-63', name: 'Search enquiries' },
    customer: { website: 'https://example.com' },
    goal: { objective: 'lead', trigger: 'thank_you_url', thank_you_url: 'https://example.com/thanks' },
    existingActions: [
        { resource: 'customers/123/conversionActions/1', name: 'Enquiry complete', objective: 'lead' },
        { resource: 'customers/123/conversionActions/2', name: 'Purchase complete', objective: 'purchase' },
    ],
    configuration: { status: 'not_configured' }, installation: { tag_detected: false }, conversionVerification: { verified: false },
    statusUrl: '/campaigns/campaign-63/conversion-goal/status',
    strategies: [{ uuid: 'strategy-1', platform: 'Google Ads' }], selectedStrategyUuid: 'strategy-1',
};

beforeEach(() => {
    formState.errors = {};
    formState.processing = false;
    postMock.mockReset();
    visitMock.mockReset();
    pollingMock.mockReset();
    polling.data = null;
    polling.error = null;
});

describe('Campaign conversion goal setup', () => {
    it('saves the chosen business outcome and precise completion page through Inertia', () => {
        render(<ConversionGoal {...props} />);
        fireEvent.change(screen.getByLabelText('Confirmation page URL'), { target: { value: 'https://example.com/enquiry-sent' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save and configure goal' }));

        expect(postMock).toHaveBeenCalledWith(route('campaigns.conversion-goal.update', { campaign: 'campaign-63' }), {
            strategy: 'strategy-1', objective: 'lead', trigger: 'thank_you_url', thank_you_url: 'https://example.com/enquiry-sent', event_name: '', action_resource: '',
        }, { preserveScroll: true });
        expect(screen.getByText(/Your campaign stays paused/)).toBeVisible();
        expect(screen.queryByRole('button', { name: /activate|launch/i })).toBeNull();
    });

    it('only offers existing actions for the selected outcome and clears stale trigger fields', () => {
        render(<ConversionGoal {...props} />);
        fireEvent.change(screen.getByLabelText('How do you know the action is complete?'), { target: { value: 'existing_action' } });
        expect(screen.getByRole('option', { name: 'Enquiry complete' })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: 'Purchase complete' })).toBeNull();
        fireEvent.change(screen.getByLabelText('Existing action for this goal'), { target: { value: 'customers/123/conversionActions/1' } });
        fireEvent.click(screen.getByRole('radio', { name: /Purchases/ }));
        expect(screen.getByLabelText('Existing action for this goal')).toHaveValue('');
        expect(screen.queryByRole('option', { name: 'Enquiry complete' })).toBeNull();
        expect(screen.getByRole('option', { name: 'Purchase complete' })).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('How do you know the action is complete?'), { target: { value: 'event' } });
        fireEvent.change(screen.getByLabelText('Website event name'), { target: { value: 'purchase_complete' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save and configure goal' }));
        expect(postMock.mock.calls[0][1]).toEqual({ strategy: 'strategy-1', objective: 'purchase', trigger: 'event', thank_you_url: '', event_name: 'purchase_complete', action_resource: '' });
    });

    it('can prepare a purchase goal without inventing a website event or claiming tracking is connected', () => {
        const { rerender } = render(<ConversionGoal {...props} />);
        fireEvent.click(screen.getByRole('radio', { name: /Purchases/ }));
        fireEvent.change(screen.getByLabelText('How do you know the action is complete?'), { target: { value: 'prepare_only' } });

        expect(screen.queryByLabelText('Website event name')).toBeNull();
        expect(screen.queryByLabelText('Confirmation page URL')).toBeNull();
        expect(screen.getByText(/without creating a website trigger or tag/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Save and configure goal' }));
        expect(postMock.mock.calls[0][1]).toEqual({ strategy: 'strategy-1', objective: 'purchase', trigger: 'prepare_only', thank_you_url: '', event_name: '', action_resource: '' });

        rerender(<ConversionGoal {...props} goal={{ objective: 'purchase', trigger: 'prepare_only' }} configuration={{ status: 'configured', request_id: 'request-1' }} installation={{ tag_detected: true }} />);
        expect(screen.getByText('Configuration checked')).toBeVisible();
        expect(screen.getByText('Website event not connected')).toBeVisible();
        expect(screen.getByText(/Its website event still needs connecting/)).toBeVisible();
        expect(screen.getByText('Test conversion needed')).toBeVisible();
        expect(screen.queryByText('Test conversion verified')).toBeNull();
    });

    it('shows server validation next to the field without claiming configuration success', () => {
        formState.errors = { thank_you_url: 'Use a confirmation page on your website.' };
        render(<ConversionGoal {...props} />);

        expect(screen.getByLabelText('Confirmation page URL')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('Use a confirmation page on your website.')).toBeVisible();
        expect(screen.queryByText('Configuration checked')).toBeNull();
        expect(screen.getByRole('button', { name: /Confirmation page URL: Use a confirmation page/ })).toBeVisible();
    });

    it('keeps verified Google configuration separate from website installation and a real conversion', () => {
        render(<ConversionGoal {...props} configuration={{ status: 'configured', request_id: 'request-1' }} />);

        const progress = screen.getByRole('region', { name: 'Goal setup progress' });
        expect(within(progress).getByText('Configuration checked')).toBeVisible();
        expect(within(progress).getByText('Installation not verified')).toBeVisible();
        expect(within(progress).getByText('Test conversion needed')).toBeVisible();
        expect(within(progress).queryByText('Test conversion verified')).toBeNull();
        expect(screen.getByText(/Your goal settings are configured. Your campaign remains paused/)).toBeVisible();
    });

    it('does not treat a saved goal awaiting campaign creation as verified configuration', () => {
        render(<ConversionGoal {...props} configuration={{ status: 'waiting_for_campaign', request_id: 'request-1' }} />);

        expect(screen.getByText('Goal saved')).toBeVisible();
        expect(screen.queryByText('Configuration checked')).toBeNull();
        expect(screen.getByRole('link', { name: 'Review and create your campaign' })).toHaveAttribute('href', route('campaigns.show', { campaign: 'campaign-63' }));
        expect(pollingMock.mock.calls.at(-1)[1].enabled).toBe(false);
    });

    it('ignores stale configured evidence from an older request while a new goal is configuring', () => {
        polling.data = { configuration: { status: 'configured', request_id: 'old-request' }, installation: { tag_detected: true }, conversionVerification: { verified: true } };
        render(<ConversionGoal {...props} configuration={{ status: 'configuring', request_id: 'new-request' }} />);

        expect(screen.queryByText('Configuration checked')).toBeNull();
        expect(screen.getByRole('button', { name: 'Configuring…' })).toBeDisabled();
        expect(screen.getByText('Test conversion needed')).toBeVisible();
        expect(pollingMock.mock.calls.at(-1)[1]).toEqual(expect.objectContaining({ enabled: true, restartKey: 'new-request:0' }));
        expect(pollingMock.mock.calls.at(-1)[1].until(polling.data)).toBe(false);
        expect(pollingMock.mock.calls.at(-1)[1].until({ configuration: { status: 'configured', request_id: 'new-request' } })).toBe(true);
    });

    it('uses fresh status for the current request and leaves real conversion proof unchanged', () => {
        polling.data = { configuration: { status: 'configured', request_id: 'request-1' }, installation: { tag_detected: true }, conversionVerification: { verified: false } };
        render(<ConversionGoal {...props} configuration={{ status: 'configuring', request_id: 'request-1' }} />);

        expect(screen.getByText('Configuration checked')).toBeVisible();
        expect(screen.getByText('Website tag detected')).toBeVisible();
        expect(screen.getByText('Test conversion needed')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Save and configure goal' })).toBeEnabled();
    });

    it('prefers a refreshed failed check over old successful polling evidence for the same request', () => {
        polling.data = { configuration: { status: 'configured', request_id: 'request-1' } };
        const { rerender } = render(<ConversionGoal {...props} configuration={{ status: 'configuring', request_id: 'request-1' }} />);
        expect(screen.getByText('Configuration checked')).toBeVisible();

        rerender(<ConversionGoal {...props} configuration={{ status: 'needs_review', request_id: 'request-1', message: 'The latest Google check needs attention.' }} />);

        expect(screen.queryByText('Configuration checked')).toBeNull();
        expect(screen.getByText('Needs attention')).toBeVisible();
        expect(screen.getByText('The latest Google check needs attention.')).toBeVisible();
    });

    it('holds configuration while the campaign draft is still being prepared', () => {
        render(<ConversionGoal {...props} canConfigure={false} />);

        expect(screen.getByRole('button', { name: 'Save and configure goal' })).toBeDisabled();
        expect(screen.getByRole('radio', { name: /Enquiries/ })).toBeDisabled();
        expect(screen.getByText(/Your campaign draft is being prepared/)).toBeVisible();
    });

    it('navigates explicitly between Search campaigns and submits only the selected strategy', () => {
        const strategies = [{ uuid: 'strategy-1', platform: 'Google Ads' }, { uuid: 'strategy-2', platform: 'Google Ads' }];
        const selectedStatusUrl = `${props.statusUrl}?strategy=strategy-2`;
        render(<ConversionGoal {...props} strategies={strategies} selectedStrategyUuid="strategy-2" statusUrl={selectedStatusUrl} />);

        expect(screen.getByLabelText('Which Search campaign is this goal for?')).toHaveValue('strategy-2');
        fireEvent.click(screen.getByRole('button', { name: 'Save and configure goal' }));
        expect(postMock.mock.calls[0][1].strategy).toBe('strategy-2');
        expect(pollingMock.mock.calls.at(-1)[0]).toBe(selectedStatusUrl);
        postMock.mockClear();
        fireEvent.change(screen.getByLabelText('Which Search campaign is this goal for?'), { target: { value: 'strategy-1' } });
        expect(visitMock).toHaveBeenCalledWith(route('campaigns.conversion-goal.edit', { campaign: 'campaign-63', strategy: 'strategy-1' }), { preserveState: false });
        expect(postMock).not.toHaveBeenCalled();
    });

    it('shows a retryable configuration failure without turning Google or website checks green', () => {
        render(<ConversionGoal {...props} configuration={{ status: 'needs_review', message: 'We could not verify the selected action. Review your choice and try again.', issues: [] }} />);

        expect(screen.getByText('We could not verify the selected action. Review your choice and try again.')).toBeVisible();
        expect(screen.getByText('Needs attention')).toBeVisible();
        expect(screen.getByText('Installation not verified')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Save and configure goal' })).toBeEnabled();
    });

    it('explains when existing actions could not be loaded rather than implying none exist', () => {
        render(<ConversionGoal {...props} existingActions={[]} availableActionsError="Existing Google conversion actions could not be loaded. Retry before choosing an existing action." />);

        expect(screen.getByText(/Existing Google conversion actions could not be loaded/)).toBeVisible();
        expect(screen.getByRole('button', { name: 'Reload existing actions' })).toBeVisible();
        expect(screen.getByRole('option', { name: 'Use an action already set up in Google Ads' })).toBeDisabled();
    });

    it('uses each handover action label so goal setup is not labelled as tracking installation', () => {
        render(<HandoverChecklist items={[
            { title: 'Campaign conversion goal', detail: 'Choose what counts as success.', url: '/goal', action_text: 'Set up goal', done: false },
            { title: 'Conversion tracking', detail: 'Install your website tag.', url: '/tag', done: false },
        ]} />);

        expect(screen.getByRole('link', { name: 'Set up goal' })).toHaveAttribute('href', '/goal');
        expect(screen.getByRole('link', { name: 'Set up tracking' })).toHaveAttribute('href', '/tag');
        expect(screen.queryByText(/Check each remaining item in your Google Ads account/)).toBeNull();
    });
});
