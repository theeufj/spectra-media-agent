import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

const { postMock, props } = vi.hoisted(() => ({
    postMock: vi.fn(),
    props: {
        flash: {},
        auth: { user: {
            id: 1, name: 'Workflow Tester', email: 'tester@example.test', customers: [],
            active_customer: { id: 1, uuid: 'test-customer', name: 'Test business', currency_code: 'AUD' },
        } },
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, as: Tag = 'a', method, ...attributes }) => <Tag {...attributes}>{children}</Tag>,
    usePage: () => ({ props }),
    router: { on: () => () => {} },
    useForm: initial => {
        const [data, update] = React.useState(initial);
        return {
            data,
            setData: (key, value) => update(previous => typeof key === 'function'
                ? key(previous) : { ...previous, [key]: value }),
            post: postMock,
            transform: () => {},
            processing: false,
            errors: {},
        };
    },
}));

// Keep the actual wizard, navigation, inputs and Headless UI transitions.
// Only unrelated network widgets and Inertia transport are replaced.
vi.mock('@/Components/NotificationBell', () => ({ default: () => null }));
vi.mock('@/Components/SupportChat', () => ({ default: () => null }));
vi.mock('@/Components/OnboardingTour', () => ({ default: () => null, startTour: () => {} }));
vi.mock('@/Components/TenantTheme', () => ({ default: () => null }));
vi.mock('@/Components/ImpersonationBanner', () => ({ default: () => null }));
vi.mock('@/Components/Toast', () => ({ useToast: () => ({}) }));
vi.mock('@/Pages/Campaigns/ProductSelection', () => ({ default: () => null }));
vi.mock('@/Components/KeywordSelector', () => ({ default: () => null }));

import CreateWizard from '@/Pages/Campaigns/CreateWizard';

function renderWizard() {
    return render(<CreateWizard
        auth={props.auth}
        selectablePlatforms={['google']}
        brandGuideline={{
            target_audience: { primary: 'Local business owners' },
            brand_voice: { description: 'Clear and helpful' },
        }}
    />);
}

function reachBudget() {
    renderWizard();
    fireEvent.click(screen.getByRole('button', { name: /Product Launch Launch a new product/ }));
    fireEvent.change(screen.getByLabelText('Campaign Name'), { target: { value: 'Workflow test' } });
    fireEvent.click(screen.getByRole('button', { name: 'Continue →' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue →' }));
}

beforeEach(() => {
    postMock.mockClear();
    const saved = new Map();
    vi.stubGlobal('localStorage', {
        getItem: key => saved.get(key) ?? null,
        setItem: (key, value) => saved.set(key, value),
        removeItem: key => saved.delete(key),
    });
    vi.stubGlobal('route', name => name ? '/__route__/' + name : { current: () => false });
    vi.stubGlobal('matchMedia', () => ({
        matches: false, addEventListener: () => {}, removeEventListener: () => {},
    }));
    vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('campaign creation workflow', () => {
    it('opens and closes the mobile drawer with the real transition components', async () => {
        renderWizard();
        fireEvent.click(screen.getByRole('button', { name: 'Open menu' }));
        const dialog = await screen.findByRole('dialog', { name: 'Site menu' });
        expect(within(dialog).getByRole('link', { name: 'New Campaign' })).toBeVisible();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close menu' }));
        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Site menu' })).toBeNull());
    });

    it('shows the selected target, fills each preset and focuses the editable field', () => {
        reachBudget();
        const input = screen.getByLabelText('What would make this campaign worth it?');
        const sales = screen.getByRole('button', { name: 'Sales worth more than the spend' });
        expect(sales).toHaveAttribute('aria-pressed', 'true');

        for (const [name, value] of [
            ['Leads under a set cost', 'Leads for under $50 each'],
            ['Sales worth more than the spend', 'At least $4 of sales for every $1 spent'],
            ['A number of enquiries a week', 'At least 10 enquiries a week'],
        ]) {
            const button = screen.getByRole('button', { name });
            fireEvent.click(button);
            expect(button).toHaveAttribute('aria-pressed', 'true');
            expect(input).toHaveValue(value);
            expect(input).toHaveFocus();
            expect(screen.getAllByRole('button', { pressed: true })).toHaveLength(1);
        }
        fireEvent.change(input, { target: { value: 'At least 15 enquiries a week' } });
        expect(screen.queryAllByRole('button', { pressed: true })).toHaveLength(0);
        expect(postMock).not.toHaveBeenCalled();
    });

    it('reaches review without submitting and requires a separate Generate strategy click', () => {
        reachBudget();
        fireEvent.change(screen.getByLabelText('Total Budget (AUD)'), { target: { value: '300' } });
        const continueButton = screen.getByRole('button', { name: 'Continue →' });
        fireEvent.click(continueButton);

        expect(screen.getByRole('heading', { name: 'Review Your Campaign' })).toBeVisible();
        expect(postMock).not.toHaveBeenCalled();
        const generate = screen.getByRole('button', { name: 'Generate strategy' });
        expect(generate).not.toBe(continueButton);
        expect(continueButton).not.toBeInTheDocument();
        fireEvent.click(generate);
        expect(postMock).toHaveBeenCalledTimes(1);
        expect(postMock.mock.calls[0][0]).toBe('/__route__/campaigns.store');
    });

    it('does not submit an intermediate step on Enter and restores the saved target', () => {
        reachBudget();
        const input = screen.getByLabelText('What would make this campaign worth it?');
        fireEvent.click(screen.getByRole('button', { name: 'Leads under a set cost' }));
        expect(fireEvent.keyDown(input, { key: 'Enter' })).toBe(false);
        expect(postMock).not.toHaveBeenCalled();

        cleanup();
        renderWizard();
        expect(screen.getByLabelText('What would make this campaign worth it?')).toHaveValue('Leads for under $50 each');
        expect(screen.getByRole('button', { name: 'Leads under a set cost' })).toHaveAttribute('aria-pressed', 'true');
    });
});
