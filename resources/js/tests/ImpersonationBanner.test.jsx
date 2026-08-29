import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, fireEvent } from '@testing-library/react';

let pageProps = {};
const postMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
    router: { post: (...args) => postMock(...args) },
}));

import ImpersonationBanner from '@/Components/ImpersonationBanner';

describe('ImpersonationBanner', () => {
    beforeEach(() => {
        postMock.mockReset();
        pageProps = {};
    });

    it('renders nothing when nobody is impersonating', () => {
        const { container } = render(<ImpersonationBanner />);

        expect(container).toBeEmptyDOMElement();
    });

    it('names the impersonated user and offers the way out', () => {
        pageProps = { impersonation: { isImpersonating: true, userName: 'Jordan Agent' } };

        const { getAllByText, getByText } = render(<ImpersonationBanner />);

        expect(getAllByText('Jordan Agent').length).toBeGreaterThan(0);

        fireEvent.click(getByText('Stop Impersonating'));
        expect(postMock).toHaveBeenCalledTimes(1);
        expect(postMock.mock.calls[0][0]).toContain('admin.impersonation.stop');
    });
});
