import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render } from '@testing-library/react';
import BrandGuidelinesIndex from '@/Pages/BrandGuidelines/Index';
import { router } from '@inertiajs/react';

const page = vi.hoisted(() => ({ props: {}, url: '/brand-guidelines?review=1' }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
    usePage: () => page,
    useForm: data => ({ data, setData: vi.fn(), put: vi.fn(), processing: false, errors: {} }),
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <>{children}</> }));
vi.mock('@/Components/BrandExtractionStatus', () => ({ default: () => null }));

const customer = { name: 'Test business', service_type: 'managed' };
const brand = { id: 48, user_verified: false, extracted_at: '2026-09-20', extraction_quality_score: 72 };

describe('brand review continuation', () => {
    beforeEach(() => {
        page.url = '/brand-guidelines?review=1';
        router.post.mockClear();
    });

    it('keeps a working next step when saving edits verifies the profile', () => {
        const { getByRole, rerender } = render(<BrandGuidelinesIndex brandGuideline={brand} customer={customer} canEdit />);
        expect(getByRole('button', { name: 'Confirm & continue →' })).toBeInTheDocument();
        rerender(<BrandGuidelinesIndex brandGuideline={{ ...brand, user_verified: true }} customer={customer} canEdit />);
        fireEvent.click(getByRole('button', { name: 'Continue →' }));
        expect(router.post).toHaveBeenCalledWith('/__route__/brand-guidelines.verify/48', { continue: true });
    });

    it('does not add the onboarding action to normal verified profile maintenance', () => {
        page.url = '/brand-guidelines';
        const { queryByRole } = render(<BrandGuidelinesIndex brandGuideline={{ ...brand, user_verified: true }} customer={customer} canEdit />);
        expect(queryByRole('button', { name: 'Continue →' })).toBeNull();
    });
});
