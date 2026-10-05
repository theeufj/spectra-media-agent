import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act, fireEvent, screen, cleanup } from '@testing-library/react';
import AdminSearch from '@/Components/AdminSearch';
import FeatureFlags from '@/Pages/Admin/FeatureFlags';
import WorkStatusBanner from '@/Components/WorkStatusBanner';
import SideNav from '@/Pages/Admin/SideNav';
import { fetchJson } from '@/utils/http';
import { router } from '@inertiajs/react';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    usePage: () => ({ url: '/admin', props: {} }),
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/ConfirmationModal', () => ({ default: ({ show, title, message, onConfirm }) => show && <div role="dialog"><h2>{title}</h2><p>{message}</p><button onClick={onConfirm}>Confirm</button></div> }));

describe('admin and queued work recovery', () => {
    beforeEach(() => { vi.useFakeTimers(); vi.clearAllMocks(); fetchJson.mockReset(); });
    afterEach(() => { cleanup(); vi.useRealTimers(); });

    it('ignores the old search response when a newer query finishes first', async () => {
        let release;
        fetchJson.mockImplementationOnce(() => new Promise(resolve => { release = resolve; }))
            .mockResolvedValue({ results: [{ id: 2, type: 'customer', title: 'New account', url: '/new' }] });
        render(<AdminSearch />);
        const input = screen.getByRole('searchbox');
        fireEvent.change(input, { target: { value: 'old' } });
        await act(() => vi.advanceTimersByTimeAsync(250));
        const signal = fetchJson.mock.calls[0][1].signal;
        fireEvent.change(input, { target: { value: 'new' } });
        await act(() => vi.advanceTimersByTimeAsync(250));
        await act(async () => release({ results: [{ id: 1, type: 'customer', title: 'Old account', url: '/old' }] }));
        expect(signal.aborted).toBe(true);
        expect(screen.getByText('New account')).toBeInTheDocument();
        expect(screen.queryByText('Old account')).not.toBeInTheDocument();
    });

    it('shows an actionable search failure instead of claiming there are no matches', async () => {
        fetchJson.mockRejectedValue(new Error('offline'));
        render(<AdminSearch />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Acme' } });
        await act(() => vi.advanceTimersByTimeAsync(250));
        expect(screen.getByRole('alert')).toHaveTextContent('Search could not load');
        expect(screen.queryByText(/No matches/)).not.toBeInTheDocument();
    });

    it('aborts a search that never responds and leaves a visible recovery state', async () => {
        fetchJson.mockImplementation(() => new Promise(() => {}));
        render(<AdminSearch />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Acme' } });
        await act(() => vi.advanceTimersByTimeAsync(30250));
        expect(screen.getByRole('alert')).toHaveTextContent('Search could not load');
        expect(fetchJson.mock.calls[0][1].signal.aborted).toBe(true);
    });

    it('customer automation targets the customer ID and global changes identify their scope', () => {
        render(<FeatureFlags auth={{ user: {} }} customerFeatures={[{ name: 'Automation', class: 'CustomerAutomation' }]} customers={[{ id: 42, name: 'Acme', flags: { CustomerAutomation: false } }]} />);
        fireEvent.click(screen.getByRole('switch', { name: 'Automation for Acme' }));
        expect(router.post).toHaveBeenCalledWith(expect.any(String), { customer_id: 42, active: true }, expect.any(Object));
        act(() => router.post.mock.calls[0][2].onFinish());
        fireEvent.click(screen.getByText('Deactivate All'));
        expect(screen.getByRole('dialog')).toHaveTextContent('every existing customer account');
        expect(router.post).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByText('Confirm'));
        expect(router.post).toHaveBeenCalledTimes(2);
    });

    it('the mobile menu exposes its expanded state and controlled navigation', () => {
        render(<SideNav />);
        const button = screen.getByRole('button', { name: 'Open menu' });
        expect(button).toHaveAttribute('aria-expanded', 'false');
        const navigation = document.getElementById(button.getAttribute('aria-controls'));
        expect(navigation).toHaveClass('hidden');
        fireEvent.click(button);
        expect(screen.getByRole('button', { name: 'Close menu' })).toHaveAttribute('aria-expanded', 'true');
        expect(navigation).toHaveClass('block');
    });

    it('queued work reloads results once on completion and stops polling', async () => {
        const run = { id: 'run-1', status: 'queued', updated_at: '2026-10-03T00:00:00Z' };
        fetchJson.mockResolvedValue({ runs: { audit: { ...run, status: 'completed', message: 'Audit saved.' } } });
        render(<WorkStatusBanner initialRun={run} task="audit" url="/status" label="Site audit" reloadOnly={['audits']} />);
        await act(() => vi.advanceTimersByTimeAsync(0));
        expect(screen.getByRole('status')).toHaveTextContent('Audit saved');
        expect(router.reload).toHaveBeenCalledOnce();
        expect(router.reload).toHaveBeenCalledWith({ only: ['audits'], preserveScroll: true });
        await act(() => vi.advanceTimersByTimeAsync(30000));
        expect(fetchJson).toHaveBeenCalledOnce();
    });

    it('a failed saved run keeps recovery instructions visible without polling', () => {
        render(<WorkStatusBanner initialRun={{ id: 'run-2', status: 'failed', updated_at: '2026-10-03T00:00:00Z', message: 'Retry the same URL.' }} task="audit" url="/status" label="Site audit" reloadOnly={['audits']} />);
        expect(screen.getByRole('alert')).toHaveTextContent('Retry the same URL');
        expect(screen.getByRole('button', { name: 'Refresh status' })).toBeInTheDocument();
        expect(fetchJson).not.toHaveBeenCalled();
    });

    it('has no banner or request when no run exists yet', () => {
        render(<WorkStatusBanner initialRun={null} task="audit" url="/status" label="Site audit" reloadOnly={['audits']} />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(fetchJson).not.toHaveBeenCalled();
    });
});
