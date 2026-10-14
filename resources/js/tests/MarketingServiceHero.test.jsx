import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
}));
vi.mock('@/Components/PageTitle', () => ({ default: () => null }));
vi.mock('@/Components/Header', () => ({ default: () => <header /> }));
vi.mock('@/Components/Footer', () => ({ default: () => <footer /> }));

import MarketingService from '@/Pages/MarketingService';

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
            primaryCta: { href: '/register', label: 'Review my business' },
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
        const review = top.getByRole('link', { name: 'Review my business' });
        const compare = top.getByRole('link', { name: 'Compare plans' });
        const article = container.querySelector('article');

        expect(top.getByText(/researches keywords, prepares Google Ads copy and monitors your campaign/)).toBeVisible();
        expect(top.getByText('Advertising spend is additional. Subscription prices are in US dollars.')).toBeVisible();
        expect(review).toHaveAttribute('href', '/register');
        expect(compare).toHaveAttribute('href', '/pricing');
        expect(review.compareDocumentPosition(article) & window.Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(compare.compareDocumentPosition(article) & window.Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(article).toHaveTextContent('Existing article content.');
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
