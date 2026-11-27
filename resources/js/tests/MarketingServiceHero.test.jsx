import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';

afterEach(() => vi.unstubAllGlobals());

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { tenant: { key: 'realpropertyads', name: 'Real Property Ads' } } }),
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
}));
vi.mock('@/Components/PageTitle', () => ({ default: () => null }));
vi.mock('@/Components/Header', () => ({ default: () => <header /> }));
vi.mock('@/Components/Footer', () => ({ default: () => <footer /> }));

import MarketingService from '@/Pages/MarketingService';
import Landing from '@/Pages/Landing';
import Pricing from '@/Pages/Pricing';
import RealEstatePricing from '@/Pages/RealEstatePricing';

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
    it('scrolls the preview instructions and form into view together on a direct ad visit and preserves the query string', () => {
        const scroll = vi.fn();
        const previous = window.Element.prototype.scrollIntoView;
        window.Element.prototype.scrollIntoView = scroll;
        window.history.replaceState({}, '', '/?utm_source=google#build-my-ads');
        try {
            const { container } = render(<Landing publicContent={homeContent} />);
            const card = container.querySelector('#build-my-ads');
            expect(scroll.mock.contexts).toContain(card);
            expect(card).toHaveClass('scroll-mt-20');
            expect(within(card).getByText(/Enter your website to see a Google Search ad/)).toBeVisible();
            expect(within(card).getByRole('form', { name: 'Preview my Google ad' })).toBeVisible();
            expect(window.location.search).toBe('?utm_source=google');
            expect(within(card).getByRole('link', { name: 'Create a free account without a preview' })).toHaveAttribute('href', '/register');
        } finally {
            window.history.replaceState({}, '', '/');
            if (previous) window.Element.prototype.scrollIntoView = previous;
            else delete window.Element.prototype.scrollIntoView;
        }
    });

    it('submits only the website, keeps it on retry, shows the usable ad and carries its token to registration', async () => {
        const website = 'https://example.test/offer';
        const fetch = vi.fn()
            .mockResolvedValueOnce(new globalThis.Response(JSON.stringify({ errors: { url: ['Check your website address.'] } }), { status: 422 }))
            .mockResolvedValueOnce(new globalThis.Response(JSON.stringify({
                success: true, url: website, preview_token: 'fixture-token', ad_copy: { headlines: ['Example offer'], descriptions: ['Built for the example site.'] },
            })))
            .mockResolvedValueOnce(new globalThis.Response(JSON.stringify({ ok: true })));
        vi.stubGlobal('fetch', fetch);
        render(<Landing publicContent={homeContent} />);
        const form = screen.getByRole('form', { name: 'Preview my Google ad' });
        const inputs = within(form);
        expect(inputs.getAllByRole('textbox')).toHaveLength(1);
        fireEvent.change(inputs.getByLabelText('Your website URL'), { target: { value: 'example.test/offer' } });
        fireEvent.submit(form);
        expect(await screen.findByRole('alert')).toHaveTextContent('Check your website address.');
        fireEvent.submit(form);
        const preview = await screen.findByRole('region', { name: 'Your ad preview' });
        expect(within(preview).getByText('Example offer', { exact: false })).toBeVisible();
        expect(fetch.mock.calls.filter(([url]) => url === '/api/demo/generate-full')).toHaveLength(2);
        const telemetry = fetch.mock.calls.filter(([url]) => url === '/spectra/conversion');
        expect(telemetry).toHaveLength(1);
        expect(JSON.parse(telemetry[0][1].body)).toEqual({ event: 'try_now' });
        expect(preview).toHaveFocus();
        expect(fetch.mock.calls[1][0]).toBe('/api/demo/generate-full');
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ url: website });
        expect(within(preview).getByRole('link', { name: 'Save this ad draft' })).toHaveAttribute('href', '/register?preview_token=fixture-token');
        expect(screen.queryByRole('form', { name: 'Preview my Google ad' })).toBeNull();
    });

    it.each([
        { headlines: [' '], descriptions: ['A real description'] },
        { headlines: ['A real headline'], descriptions: [] },
    ])('keeps the URL editable for incomplete ad copy without recording a completed preview', async (copy) => {
        const fetch = vi.fn().mockResolvedValueOnce(new globalThis.Response(JSON.stringify({
            success: true, url: 'https://example.test', ad_copy: copy,
        })));
        vi.stubGlobal('fetch', fetch);
        render(<Landing publicContent={homeContent} />);
        const website = screen.getByLabelText('Your website URL');
        fireEvent.change(website, { target: { value: 'example.test' } });
        fireEvent.submit(screen.getByRole('form', { name: 'Preview my Google ad' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('We could not create a complete ad');
        expect(website).toHaveValue('example.test');
        expect(screen.getByRole('link', { name: 'Create a free account without a preview' })).toBeVisible();
        expect(screen.queryByRole('link', { name: 'Save this ad draft' })).toBeNull();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('announces progress during generation and preserves the form after a network failure', async () => {
        let reject;
        const fetch = vi.fn(() => new Promise((resolve, rejectRequest) => { reject = rejectRequest; }));
        vi.stubGlobal('fetch', fetch);
        render(<Landing publicContent={homeContent} />);
        const form = screen.getByRole('form', { name: 'Preview my Google ad' });
        fireEvent.change(screen.getByLabelText('Your website URL'), { target: { value: 'example.test' } });
        fireEvent.submit(form);
        fireEvent.submit(form);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(form).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByRole('status')).toHaveTextContent('Reading your site and preparing an ad preview.');
        expect(screen.getByRole('button', { name: 'Building your preview…' })).toBeDisabled();
        expect(screen.getByRole('link', { name: 'Create a free account without a preview' })).toBeVisible();
        reject(new TypeError('Network failure'));
        expect(await screen.findByRole('alert')).toHaveTextContent('Please retry.');
        expect(screen.getByLabelText('Your website URL')).toHaveValue('example.test');
        expect(screen.getByRole('button', { name: 'Preview my Google ad' })).toBeEnabled();
    });

});

describe('Site to Spend public offer', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => new globalThis.Response(JSON.stringify({ ok: true }))));
    });

    it('shows one Google plan and an ungated illustrative report', () => {
        const { container } = render(<Landing publicContent={homeContent} plans={[
            plan({ price_cents: 9900 }), plan({ id: 2, name: 'Growth', slug: 'growth', price_cents: 24900 }),
        ]} />);
        expect(screen.getByRole('heading', { name: 'Starter' })).toBeVisible();
        expect(screen.getByText('US$99')).toBeVisible();
        expect(screen.queryByText('Growth')).toBeNull();
        const report = within(container.querySelector('#sample-report'));
        expect(report.getByText(/fictional campaign/i)).toBeVisible();
        expect(report.getByText(/plumbing courses.*negative keyword/i)).toBeVisible();
        expect(report.getByText(/US\$280 in media spend ÷ 7 tracked forms = US\$40/)).toBeVisible();
        expect(screen.getByRole('link', { name: 'See an example report' })).toHaveAttribute('href', '#sample-report');
    });

    it('explains actual billing terms before signup and sends signed-in customers to workspace billing', () => {
        const plans = [plan({ price_cents: 9900 })];
        const page = render(<Pricing publicContent={homeContent} plans={plans} earlyExitFeeUsd={999} />);
        const pricingVisits = globalThis.fetch.mock.calls.filter(([url]) => url === '/spectra/conversion');
        expect(pricingVisits).toHaveLength(1);
        expect(JSON.parse(pricingVisits[0][1].body)).toEqual({ event: 'pricing_visit' });
        expect(screen.getByText(/first 3 paid months/)).toBeVisible();
        expect(screen.getByText(/After one US\$99 payment, the remaining fee would be US\$900/)).toBeVisible();
        expect(screen.getByText(/US\$140 in ad credit/)).toBeVisible();
        expect(screen.getByRole('link', { name: 'Read the early-exit terms' })).toHaveAttribute('href', '/terms-of-service#early-exit');
        expect(screen.getByRole('link', { name: 'Create your workspace' })).toHaveAttribute('href', '/register');
        page.rerender(<Pricing publicContent={homeContent} plans={plans} earlyExitFeeUsd={999} earlyExitMonths={1} auth={{ user: { id: 1 } }} />);
        expect(screen.getByText(/your first paid month can trigger/)).toBeVisible();
        expect(screen.queryByText(/remaining fee would be/)).toBeNull();
        expect(screen.getByRole('link', { name: 'View workspace billing' })).toHaveAttribute('href', '/subscription/pricing');
    });

    it('leaves the free preview accessible when the paid plan is unavailable', () => {
        render(<Pricing publicContent={homeContent} earlyExitFeeUsd={999} earlyExitMonths={5} />);
        expect(screen.getByText(/first 5 paid months/)).toBeVisible();
        expect(screen.queryByText(/remaining fee would be/)).toBeNull();
        expect(screen.getByRole('link', { name: 'Build my ads' })).toHaveAttribute('href', '/#build-my-ads');
        expect(screen.queryByRole('link', { name: 'Create your workspace' })).toBeNull();
    });

    it('keeps the property site’s additional plans and standalone setup', () => {
        render(<RealEstatePricing publicContent={homeContent} plans={[
            plan({ price_cents: 9900 }), plan({ id: 2, name: 'Growth', slug: 'growth', price_cents: 24900 }),
        ]} setupFeeUsd={999} />);
        expect(screen.getByRole('heading', { name: 'Growth' })).toBeVisible();
        expect(screen.getByText('US$999')).toBeVisible();
    });
});
