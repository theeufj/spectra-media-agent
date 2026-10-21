import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';

const transport = vi.hoisted(() => ({ post: vi.fn(), errors: {} }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, ...props }) => <a href={href} {...props}>{children}</a>,
    useForm: initial => {
        const [data, update] = React.useState(initial);
        const [errors, setErrors] = React.useState({});
        const transform = React.useRef(value => value);

        return {
            data,
            setData: (key, value) => update(previous => ({ ...previous, [key]: value })),
            transform: callback => { transform.current = callback; },
            post: url => {
                transport.post(url, transform.current(data));
                setErrors(transport.errors);
            },
            processing: false,
            errors,
        };
    },
}));

// Render the actual page, fields, service choices and error summary. Only the
// surrounding app shell and Inertia transport are outside this workflow.
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <>{children}</> }));

import QuickStart from '@/Pages/QuickStart';

const auth = { user: { id: 7 } };

beforeEach(() => {
    transport.post.mockReset();
    transport.errors = {};
});

afterEach(cleanup);

describe('Quick Start onboarding', () => {
    it('renders the first onboarding screen and requires a website before starting', () => {
        render(<QuickStart auth={auth} />);

        expect(screen.getByRole('heading', { name: 'Let’s start with your business' })).toBeVisible();
        expect(screen.getByLabelText('Your website address')).toHaveValue('');
        expect(screen.getByRole('button', { name: 'Go' })).toBeDisabled();
        expect(screen.getByRole('radio', { name: /Manage it for me/ })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('link', { name: 'set it up manually' })).toHaveAttribute('href', '/__route__/customers.create');
        expect(transport.post).not.toHaveBeenCalled();
    });

    it('submits the edited website and selected service through the form transform', () => {
        render(<QuickStart auth={auth} />);

        fireEvent.change(screen.getByLabelText('Your website address'), { target: { value: ' example.test ' } });
        fireEvent.click(screen.getByRole('radio', { name: /Set it up once/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Go' }));

        expect(transport.post).toHaveBeenCalledExactlyOnceWith('/__route__/quick-start.process', {
            website_url: 'https://example.test',
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
            service_type: 'setup_only',
        });
    });

    it('shows server validation errors without losing the entered website and links back to its field', () => {
        transport.errors = {
            website_url: 'We could not read that website.',
            timezone: 'Please choose a valid time zone.',
        };
        render(<QuickStart auth={auth} demoUrl="https://example.test" />);
        fireEvent.click(screen.getByRole('button', { name: 'Go' }));

        const summary = screen.getByText('Please check the following before saving:').closest('[role="alert"]');
        expect(summary).toHaveFocus();
        expect(within(summary).getByText('Time zone: Please choose a valid time zone.')).toBeVisible();
        const website = screen.getByLabelText('Your website address');
        expect(website).toHaveValue('https://example.test');
        expect(website).toHaveAttribute('aria-invalid', 'true');
        expect(website).toHaveAccessibleDescription('We could not read that website.');

        fireEvent.click(within(summary).getByRole('button', { name: 'Website address: We could not read that website.' }));
        expect(website).toHaveFocus();
    });
});
