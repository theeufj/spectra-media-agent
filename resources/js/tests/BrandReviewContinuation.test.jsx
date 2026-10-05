import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render } from '@testing-library/react';
import BrandGuidelinesIndex from '@/Pages/BrandGuidelines/Index';
import { router } from '@inertiajs/react';

const page = vi.hoisted(() => ({ props: {}, url: '/brand-guidelines?review=1' }));
const actions = vi.hoisted(() => ({ put: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, ...props }) => <a href={href} {...props}>{children}</a>,
    router: { post: vi.fn() },
    usePage: () => page,
    useForm: initial => {
        const [data, setData] = React.useState(initial);
        return { data, setData, put: actions.put, processing: false, errors: {}, clearErrors: vi.fn(), setError: vi.fn() };
    },
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <>{children}</> }));
vi.mock('@/Components/BrandExtractionStatus', () => ({ default: () => null }));
vi.mock('@/Components/ConfirmationModal', () => ({ default: () => null }));

const customer = { name: 'Test business', service_type: 'managed', country: 'AU', currency_code: 'AUD' };
const brand = { id: 48, profile_version: 3, user_verified: false, extracted_at: '2026-09-20', brand_voice: { primary_tone: 'Saved tone' } };

describe('brand review continuation', () => {
    beforeEach(() => { page.url = '/brand-guidelines?review=1'; router.post.mockClear(); router.post.mockImplementation((_url, _data, options) => options?.onFinish?.()); actions.put.mockClear(); });

    it('approves the displayed version and keeps onboarding continuation available after approval', () => {
        const { getByRole, rerender } = render(<BrandGuidelinesIndex brandGuideline={brand} customer={customer} canEdit />);
        fireEvent.click(getByRole('button', { name: 'Approve profile & continue' }));
        expect(router.post).toHaveBeenCalledWith('/__route__/brand-guidelines.verify/48', { continue: true, profile_version: 3 }, expect.any(Object));
        rerender(<BrandGuidelinesIndex brandGuideline={{ ...brand, user_verified: true }} customer={customer} canEdit />);
        fireEvent.click(getByRole('button', { name: 'Continue to campaign' }));
        expect(router.post).toHaveBeenCalledTimes(2);
    });

    it('saves a draft separately and restores saved values on cancel before approval', () => {
        const { getByRole, getByLabelText, getByText, queryByRole } = render(<BrandGuidelinesIndex brandGuideline={brand} customer={customer} canEdit />);
        fireEvent.click(getByRole('button', { name: 'Voice & personality' }));
        fireEvent.click(getByRole('button', { name: 'Edit profile' }));
        fireEvent.change(getByLabelText('Primary tone'), { target: { value: 'Unsaved tone' } });
        expect(queryByRole('button', { name: 'Approve profile & continue' })).toBeNull();
        fireEvent.click(getByRole('button', { name: 'Save draft' }));
        expect(actions.put).toHaveBeenCalledWith('/__route__/brand-guidelines.update/48', expect.any(Object));
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(getByRole('button', { name: 'Cancel edits' }));
        expect(getByText('Saved tone')).toBeInTheDocument();
        expect(queryByRole('textbox', { name: 'Primary tone' })).toBeNull();
        fireEvent.click(getByRole('button', { name: 'Approve profile & continue' }));
        expect(router.post).toHaveBeenCalledWith('/__route__/brand-guidelines.verify/48', expect.objectContaining({ profile_version: 3 }), expect.any(Object));
    });

    it('does not add onboarding continuation to normal verified maintenance', () => {
        page.url = '/brand-guidelines';
        const { queryByRole } = render(<BrandGuidelinesIndex brandGuideline={{ ...brand, user_verified: true }} customer={customer} canEdit />);
        expect(queryByRole('button', { name: 'Continue to campaign' })).toBeNull();
    });
});
