import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import Backlinks from '@/Pages/SEO/Backlinks';

const mocks = vi.hoisted(() => ({ post: vi.fn(), poll: { data: null, failureStreak: 0 } }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, ...props }) => <a {...props}>{children}</a>, router: { post: mocks.post } }));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => mocks.poll }));
beforeEach(() => { mocks.post.mockClear(); mocks.poll = { data: null, failureStreak: 0 }; });
afterEach(cleanup);
const profile = {
    provider: 'Moz', status: 'complete', analyzed_at: '2026-09-22T10:00:00Z',
    indexed_linking_pages: 20, referring_domains: 16, domain_authority: 3, sample_size: 1, sample_available: true,
    backlinks: [{ source_url: 'https://publisher.example/article', target_url: 'https://example.com/offer',
        anchor_text: 'Product link', rel: 'nofollow', domain_authority: 70, last_seen: '2026-09-20' }],
};

describe('backlink analysis', () => {
    it('offers a real run action instead of an indefinite loading placeholder', () => {
        render(<Backlinks domain="example.com" profile={null} />);
        fireEvent.click(screen.getByRole('button', { name: 'Run analysis' }));
        expect(mocks.post.mock.calls[0][0]).toBe(route('seo.backlinks.refresh'));
        expect(screen.queryByText('Loading backlink data...')).toBeNull();
    });

    it('shows source and destination links alongside actual domain metrics', () => {
        render(<Backlinks domain="example.com" profile={profile} />);
        expect(screen.getByRole('link', { name: 'https://publisher.example/article' })).toHaveAttribute('href', 'https://publisher.example/article');
        expect(screen.getByText('Domain authority (Moz)').parentElement).toHaveTextContent('3');
        expect(screen.getByText('Linking pages (Moz)').parentElement).toHaveTextContent('20');
        expect(screen.getByText('nofollow')).toBeVisible();
    });

    it('renders missing provider data as unavailable without fabricated zeros', () => {
        render(<Backlinks domain="example.com" profile={{ status: 'unavailable', warnings: ['Moz request failed (HTTP 400).'], backlinks: [] }} />);
        expect(screen.getByText('Linking pages (Moz)').parentElement).toHaveTextContent('—');
        expect(screen.getByText(/HTTP 400/)).toBeVisible();
        expect(screen.queryByText('Toxic Links')).toBeNull();
    });

    it('replaces running status with the completed report received through polling', () => {
        const { rerender } = render(<Backlinks domain="example.com" profile={null} run={{ status: 'running' }} />);
        expect(screen.getByRole('button', { name: 'Analyzing backlinks…' })).toBeDisabled();
        mocks.poll = { data: { domain: 'example.com', profile, run: { status: 'completed' } }, failureStreak: 0 };
        rerender(<Backlinks domain="example.com" profile={null} run={{ status: 'running' }} />);
        expect(screen.getByRole('button', { name: 'Refresh analysis' })).toBeEnabled();
        expect(screen.getByText('Linking pages (Moz)').parentElement).toHaveTextContent('20');
    });

    it('keeps a previous report visible when the latest refresh fails', () => {
        render(<Backlinks domain="example.com" profile={profile} run={{ status: 'failed', error: 'Analysis failed. Previous report retained.' }} />);
        expect(screen.getByRole('alert')).toHaveTextContent('Previous report retained');
        expect(screen.getByText('Linking pages (Moz)').parentElement).toHaveTextContent('20');
    });
});
