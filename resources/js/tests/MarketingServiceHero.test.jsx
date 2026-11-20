import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { previewContactFor } from '@/utils/previewContact';

afterEach(() => vi.unstubAllGlobals());

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
}));
vi.mock('@/Components/PageTitle', () => ({ default: () => null }));
vi.mock('@/Components/Header', () => ({ default: () => <header /> }));
vi.mock('@/Components/Footer', () => ({ default: () => <footer /> }));

import MarketingService from '@/Pages/MarketingService';
import Landing from '@/Pages/Landing';

const homeContent = { headline: 'Preview your ads', intro: 'Explore your business profile free.', sections: [], links: [] };

const service = {
    slug: 'google-ads-management',
    eyebrow: 'For small businesses',
    headline: 'Google Ads management built around your actual customers',
    intro: 'Existing service introduction.',
    content: '<h2>Detailed management information</h2><p>Existing article content.</p>',
    faqs: [],
};

function plan(overrides = {}) {
    return {
        id: 1,
        name: 'Starter',
        slug: 'starter',
        price_cents: 17900,
        billing_interval: 'month',
        is_active: true,
        is_free: false,
        ...overrides,
    };
}

function managementContent(price = 'US$179/month') {
    return {
        type: 'service',
        hero: {
            headline: price ? `Google Ads management from ${price}` : service.headline,
            intro: 'Site to Spend researches keywords, prepares Google Ads copy and monitors your campaign on a managed plan. Review your business information and ads before launch.',
            primaryCta: { href: '/#build-my-ads', label: 'Build my ads' },
            secondaryCta: { href: '/pricing', label: 'Compare plans' },
            note: 'Advertising spend is additional. Subscription prices are in US dollars.',
        },
    };
}

function hero() {
    return screen.getByRole('heading', { level: 1 }).closest('section');
}

describe('Google Ads management landing hero', () => {
    it('renders the server price with its US dollar qualifier and updates when the public hero changes', () => {
        const plans = [plan({ id: 2, slug: 'growth', price_cents: 27900 }), plan()];
        const page = render(<MarketingService service={service} plans={plans} publicContent={managementContent()} />);

        expect(within(hero()).getByRole('heading', { level: 1 })).toHaveTextContent('Google Ads management from US$179/month');

        page.rerender(<MarketingService service={service} plans={[plan({ price_cents: 19999 })]} publicContent={managementContent('US$199.99/month')} />);
        expect(within(hero()).getByRole('heading', { level: 1 })).toHaveTextContent('Google Ads management from US$199.99/month');
        expect(hero()).not.toHaveTextContent('US$149');
    });

    it('keeps the original introduction when shared hero content is unavailable', () => {
        render(<MarketingService service={service} plans={[plan()]} />);

        expect(within(hero()).getByRole('heading', { level: 1 })).toHaveTextContent(service.headline);
        expect(hero()).toHaveTextContent(service.intro);
        expect(hero()).not.toHaveTextContent('US$');
    });

    it('explains the service, separates advertising spend and provides working actions before the article', () => {
        const { container } = render(<MarketingService service={service} plans={[plan()]} publicContent={managementContent()} />);
        const top = within(hero());
        const review = top.getByRole('link', { name: 'Build my ads' });
        const compare = top.getByRole('link', { name: 'Compare plans' });
        const article = container.querySelector('article');

        expect(top.getByText(/researches keywords, prepares Google Ads copy and monitors your campaign/)).toBeVisible();
        expect(top.getByText('Advertising spend is additional. Subscription prices are in US dollars.')).toBeVisible();
        expect(review).toHaveAttribute('href', '/#build-my-ads');
        expect(compare).toHaveAttribute('href', '/pricing');
        expect(review.compareDocumentPosition(article) & window.Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(compare.compareDocumentPosition(article) & window.Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(article).toHaveTextContent('Existing article content.');
    });

    it.each(['google-ads-management', 'ai-ads-management', 'google-ads-setup'])('links %s to the homepage form', (slug) => {
        const { container } = render(<MarketingService service={{ ...service, slug }} plans={[plan()]} publicContent={managementContent()} />);
        expect(within(hero()).getByRole('link', { name: 'Build my ads' })).toHaveAttribute('href', '/#build-my-ads');
        expect(screen.queryByRole('form')).toBeNull();
        expect(container.querySelector('a[href^="/register"]')).toBeNull();
    });

    it('does not invent a starting price when no eligible monthly plan is available', () => {
        render(<MarketingService service={service} plans={[plan({ billing_interval: 'year' })]} publicContent={managementContent(null)} />);

        expect(within(hero()).getByRole('heading', { level: 1 })).toHaveTextContent(service.headline);
        expect(hero()).not.toHaveTextContent('US$');
        expect(within(hero()).getByRole('link', { name: 'Compare plans' })).toHaveAttribute('href', '/pricing');
    });

    it('preserves the one-time setup fee and paused handover instead of advertising a monthly plan', () => {
        const setup = {
            ...service,
            slug: 'google-ads-setup',
            headline: 'Google Ads setup for US$1,099.50 once',
            intro: 'A paused campaign build and handover. Advertising spend is separate.',
        };
        render(<MarketingService service={setup} plans={[plan()]} publicContent={managementContent()} />);

        expect(within(hero()).getByRole('heading', { level: 1 })).toHaveTextContent(setup.headline);
        expect(hero()).toHaveTextContent(setup.intro);
        expect(hero()).not.toHaveTextContent('/month');
        expect(screen.queryByRole('heading', { name: 'Current management plans' })).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Prepare your business for a one-time build' })).toBeInTheDocument();
    });

    it('keeps the AI management page headline and introduction unchanged', () => {
        const ai = { ...service, slug: 'ai-ads-management', headline: 'AI ads management with a plan you can inspect' };
        render(<MarketingService service={ai} plans={[plan()]} publicContent={managementContent()} />);

        expect(within(hero()).getByRole('heading', { level: 1 })).toHaveTextContent(ai.headline);
        expect(hero()).toHaveTextContent(ai.intro);
        expect(hero()).not.toHaveTextContent('US$179/month');
    });
});

describe('Homepage ad preview', () => {
    it('scrolls to the mounted form for a direct ad visit and preserves the query string', () => {
        const scroll = vi.fn();
        const previous = window.Element.prototype.scrollIntoView;
        window.Element.prototype.scrollIntoView = scroll;
        window.history.replaceState({}, '', '/?utm_source=google#build-my-ads');
        try {
            const { container } = render(<Landing publicContent={homeContent} />);
            expect(scroll.mock.contexts).toContain(screen.getByRole('form', { name: 'Build my ads' }));
            expect(window.location.search).toBe('?utm_source=google');
            expect(container.querySelector('a[href^="/register"]')).toBeNull();
        } finally {
            window.history.replaceState({}, '', '/');
            if (previous) window.Element.prototype.scrollIntoView = previous;
            else delete window.Element.prototype.scrollIntoView;
        }
    });

    it('submits a single preview in place, keeps the contact and website on retry, and continues to registration', async () => {
        const website = 'https://example.test/offer';
        const fetch = vi.fn()
            .mockResolvedValueOnce(new globalThis.Response(JSON.stringify({ errors: { url: ['Check your website address.'] } }), { status: 422 }))
            .mockResolvedValueOnce(new globalThis.Response(JSON.stringify({
                success: true, url: website, ad_copy: { headlines: ['Example offer'], descriptions: ['Built for the example site.'] },
            })));
        vi.stubGlobal('fetch', fetch);
        render(<Landing publicContent={homeContent} />);
        const form = screen.getByRole('form', { name: 'Build my ads' });
        const inputs = within(form);
        fireEvent.change(inputs.getByLabelText('First name'), { target: { value: 'Jordan' } });
        fireEvent.change(inputs.getByLabelText('Your email address'), { target: { value: 'jordan@example.test' } });
        fireEvent.change(inputs.getByLabelText('Your website URL'), { target: { value: 'example.test/offer' } });
        fireEvent.submit(form);
        expect(await screen.findByRole('alert')).toHaveTextContent('Check your website address.');
        expect(inputs.getByLabelText('Your email address')).toHaveValue('jordan@example.test');
        fireEvent.submit(form);
        const preview = await screen.findByRole('region', { name: 'Your ad preview' });
        expect(within(preview).getByText('Example offer', { exact: false })).toBeVisible();
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(fetch.mock.calls[1][0]).toBe('/api/demo/generate-full');
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ url: website, first_name: 'Jordan', email: 'jordan@example.test' });
        expect(within(preview).getByRole('link').getAttribute('href')).toBe('/register?demo_url=' + encodeURIComponent(website));
        expect(previewContactFor(website)).toEqual({ name: 'Jordan', email: 'jordan@example.test' });
        expect(previewContactFor('https://unrelated.test')).toEqual({});
        expect(screen.queryByRole('form', { name: 'Build my ads' })).toBeNull();
    });

    it('announces progress during generation and preserves the form after a network failure', async () => {
        let reject;
        const fetch = vi.fn(() => new Promise((resolve, rejectRequest) => { reject = rejectRequest; }));
        vi.stubGlobal('fetch', fetch);
        render(<Landing publicContent={homeContent} />);
        const form = screen.getByRole('form', { name: 'Build my ads' });
        fireEvent.change(screen.getByLabelText('First name'), { target: { value: 'Lee' } });
        fireEvent.change(screen.getByLabelText('Your email address'), { target: { value: 'lee@example.test' } });
        fireEvent.change(screen.getByLabelText('Your website URL'), { target: { value: 'example.test' } });
        fireEvent.submit(form);
        fireEvent.submit(form);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(form).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByRole('status')).toHaveTextContent('Reading your site and preparing an ad preview.');
        expect(screen.getByRole('button', { name: 'Building your preview…' })).toBeDisabled();
        reject(new TypeError('Network failure'));
        expect(await screen.findByRole('alert')).toHaveTextContent('Please retry.');
        expect(screen.getByLabelText('Your website URL')).toHaveValue('example.test');
        expect(screen.getByLabelText('First name')).toHaveValue('Lee');
        expect(screen.getByLabelText('Your email address')).toHaveValue('lee@example.test');
        expect(screen.getByRole('button', { name: 'Build my ads' })).toBeEnabled();
    });

});
