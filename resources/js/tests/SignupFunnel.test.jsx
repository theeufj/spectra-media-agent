import React from 'react';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import WebsiteAdPreview from '@/Components/Marketing/WebsiteAdPreview';
import MarketingService from '@/Pages/MarketingService';
import Register from '@/Pages/Auth/Register';
import Landing from '@/Pages/Landing';
import Pricing from '@/Pages/Pricing';
import HowItWorks from '@/Pages/HowItWorks';
import Features from '@/Pages/Features';
import About from '@/Pages/About';
import Guides from '@/Pages/Blog/Index';
import Guide from '@/Pages/Blog/Article';
import { initConversions } from '@/utils/conversions';

const { submit, oauth } = vi.hoisted(() => ({ submit: vi.fn(), oauth: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, onClick }) => <a href={href} onClick={e => { e.preventDefault(); onClick?.(e); }}>{children}</a>,
    router: { post: oauth },
    usePage: () => ({ props: {} }),
    useForm: initial => {
        const [data, update] = React.useState(initial);
        return {
            data, errors: {}, processing: false, reset: vi.fn(), post: submit,
            setData: (key, value) => update(current => ({ ...current, [key]: value })),
        };
    },
}));
vi.mock('@/Layouts/GuestLayout', () => ({ default: ({ children }) => <>{children}</> }));
vi.mock('@/Components/PageTitle', () => ({ default: () => null }));
vi.mock('@/Components/Header', () => ({ default: () => null }));
vi.mock('@/Components/Footer', () => ({ default: () => null }));

const tenant = { key: 'sitetospend' };
const website = 'https://business.example/private-offer';
const completed = {
    success: true, url: website, preview_token: 'private-token',
    ad_copy: { headlines: ['Local roof inspections'], descriptions: ['Book a visit to check leaking tiles.'] },
};
let preview;
let events;

beforeEach(() => {
    vi.clearAllMocks();
    initConversions({});
    preview = vi.fn();
    events = [];
    vi.stubGlobal('gtag', vi.fn());
    vi.stubGlobal('fetch', vi.fn((path, options) => {
        if (path === '/api/demo/generate-full') return preview();
        if (path === '/funnel/stages') {
            events.push(JSON.parse(options.body));
        }
        return Promise.resolve(new globalThis.Response(null, { status: 204 }));
    }));
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    window.history.replaceState(null, '', '/');
});

function fillPreview() {
    fireEvent.change(screen.getByLabelText('Your website URL'), { target: { value: website } });
    return screen.getByRole('form', { name: 'Preview my Google ad' });
}

describe('owned first-party signup funnel', () => {
    it('waits for a visible rendered landing, sends it once, and excludes unrelated service pages and tenants', () => {
        let state = 'hidden';
        vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => state);
        const service = slug => ({ slug, faqs: [], content: '', headline: 'Service' });
        const page = render(<MarketingService service={service('google-ads-setup')} tenant={tenant} />);
        page.rerender(<MarketingService service={service('google-ads-management')} tenant={{ key: 'another-site' }} />);
        page.rerender(<MarketingService service={service('google-ads-management')} tenant={tenant} />);
        expect(events).toEqual([]);
        state = 'visible';
        fireEvent(document, new Event('visibilitychange'));
        fireEvent(document, new Event('visibilitychange'));
        expect(events).toEqual([{ stage: 'landing_visible' }]);
    });

    it.each([
        ['home', Landing], ['pricing', Pricing], ['features', Features], ['how it works', HowItWorks],
        ['about', About], ['guide index', Guides], ['guide article', Guide], ['AI service', MarketingService],
    ])('recognizes a visible %s entry for flagship guests only', (_, Component) => {
        const props = { publicContent: { sections: [], links: [] }, article: { title: 'A guide', content: '' },
            service: { slug: 'ai-ads-management', faqs: [], content: '' } };
        const page = render(<Component {...props} tenant={{ key: 'realpropertyads' }} />);
        page.rerender(<Component {...props} tenant={tenant} auth={{ user: { id: 5 } }} />);
        expect(events).toEqual([]);
        page.rerender(<Component {...props} tenant={tenant} />);
        expect(events).toEqual([{ stage: 'landing_visible' }]);
    });

    it('tracks only real preview interactions and visible results, keeping the website and contact details out of telemetry', async () => {
        let resolve;
        preview.mockImplementation(() => new Promise(r => { resolve = r; }));
        window.history.replaceState(null, '', '/?gclid=private-click');
        render(<WebsiteAdPreview measureFunnel />);
        expect(events).toEqual([{ stage: 'landing_visible' }]);
        const form = fillPreview();
        fireEvent.submit(form);
        fireEvent.submit(form);
        expect(preview).toHaveBeenCalledTimes(1);
        expect(events).toEqual(['landing_visible', 'preview_started', 'preview_submitted'].map(stage => ({ stage })));
        let state = 'hidden';
        vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => state);
        await act(async () => resolve(new globalThis.Response(JSON.stringify(completed))));
        expect(screen.getByRole('region', { name: 'Your ad preview' })).toBeVisible();
        expect(events.some(event => event.stage === 'preview_succeeded')).toBe(false);
        state = 'visible';
        fireEvent(document, new Event('visibilitychange'));
        fireEvent(document, new Event('visibilitychange'));
        const save = screen.getByRole('link', { name: 'Save this ad draft' });
        expect(save.getAttribute('href')).toBe('/register?preview_token=private-token');
        expect(events).not.toContainEqual({ stage: 'save_clicked' });
        fireEvent.click(save);
        fireEvent.click(save);
        expect(events).toEqual(['landing_visible', 'preview_started', 'preview_submitted', 'preview_succeeded', 'save_clicked'].map(stage => ({ stage })));
        for (const [, options] of fetch.mock.calls.filter(([path]) => path === '/funnel/stages')) {
            expect(options).toMatchObject({ method: 'POST', keepalive: true, credentials: 'same-origin', referrerPolicy: 'no-referrer' });
        }
        expect(gtag).not.toHaveBeenCalled();
    });

    it('excludes incomplete results and displays without a server-issued draft token', async () => {
        preview.mockResolvedValueOnce(new globalThis.Response(JSON.stringify({ ...completed, ad_copy: { headlines: ['One headline'] } })))
            .mockResolvedValueOnce(new globalThis.Response(JSON.stringify({ ...completed, preview_token: null })));
        render(<WebsiteAdPreview measureFunnel />);
        fireEvent.submit(fillPreview());
        await screen.findByRole('alert');
        fireEvent.submit(fillPreview());
        const account = await screen.findByRole('link', { name: 'Create a free account' });
        expect(events).not.toContainEqual({ stage: 'preview_succeeded' });
        fireEvent.click(account);
        expect(events).toContainEqual({ stage: 'signup_cta_clicked' });
        expect(events).not.toContainEqual({ stage: 'save_clicked' });
    });

    it('records the pricing page signup link without requiring a preview', () => {
        render(<Pricing tenant={tenant} publicContent={{ sections: [], links: [] }} plans={[{
            id: 1, slug: 'starter', price_cents: 9900, is_active: true, billing_interval: 'month',
        }]} />);
        fireEvent.click(screen.getByRole('link', { name: 'Create your workspace' }));
        expect(events).toEqual([{ stage: 'landing_visible' }, { stage: 'signup_cta_clicked' }]);
    });

    it.each([
        [422, 'validation'], [429, 'rate_limited'], [503, 'unavailable'], ['timeout', 'timeout'], ['offline', 'network'],
    ])('reports a bounded reason for %s and leaves the signup action available', async (failure, expected) => {
        if (failure === 'timeout') preview.mockRejectedValue(new globalThis.DOMException('Private detail', 'AbortError'));
        else if (failure === 'offline') preview.mockRejectedValue(new TypeError('Private network detail'));
        else preview.mockResolvedValue(new globalThis.Response('{"errors":{"email":["Provided private value"]}}', { status: failure }));
        render(<WebsiteAdPreview measureFunnel />);
        fireEvent.submit(fillPreview());
        await screen.findByRole('alert');
        fireEvent.click(screen.getByRole('link', { name: 'Create a free account without a preview' }));
        expect(events).toContainEqual({ stage: 'preview_failed', reason: expected });
        expect(events).toContainEqual({ stage: 'signup_cta_clicked' });
        expect(events.some(e => e.stage === 'preview_succeeded')).toBe(false);
    });

    it('does not label leaving a pending preview as a service failure', async () => {
        let reject;
        preview.mockImplementation(() => new Promise((resolve, r) => { reject = r; }));
        const page = render(<WebsiteAdPreview measureFunnel />);
        fireEvent.submit(fillPreview());
        page.unmount();
        await act(async () => reject(new globalThis.DOMException('Aborted', 'AbortError')));
        expect(events.some(e => e.stage === 'preview_failed')).toBe(false);
    });

    it('can display the real preview when the diagnostics endpoint is unavailable', async () => {
        fetch.mockImplementation(path => {
            if (path === '/funnel/stages') return Promise.reject(new TypeError('Offline'));
            if (path === '/api/demo/generate-full') return Promise.resolve(new globalThis.Response(JSON.stringify(completed)));
            return Promise.resolve(new globalThis.Response(null, { status: 204 }));
        });
        render(<WebsiteAdPreview measureFunnel />);
        fireEvent.submit(fillPreview());
        expect(await screen.findByRole('region', { name: 'Your ad preview' })).toBeVisible();
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('leaves auditing password and Google submissions to their server endpoints without transmitting form values', () => {
        window.history.replaceState(null, '', '/register?demo_url=' + encodeURIComponent(website));
        render(<Register tenant={tenant} enabledPlatforms={[{ slug: 'google', name: 'Google' }]} />);
        expect(events).toEqual([{ stage: 'landing_visible' }]);
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Private name' } });
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'private@example.test' } });
        const consent = screen.getByRole('checkbox', { name: /hashed version/i });
        expect(consent).not.toBeChecked();
        fireEvent.click(consent);
        const form = screen.getByRole('button', { name: 'Create account' }).closest('form');
        fireEvent.invalid(screen.getByLabelText('Name'));
        fireEvent.submit(form);
        submit.mock.calls[0][1].onSuccess();
        fireEvent.click(screen.getByRole('button', { name: 'Sign up with Google' }));
        expect(oauth).toHaveBeenCalledOnce();
        expect(events).toEqual([
            { stage: 'landing_visible' }, { stage: 'signup_started' }, { stage: 'signup_failed', reason: 'validation' },
        ]);
        expect(gtag).not.toHaveBeenCalled();
    });
});
