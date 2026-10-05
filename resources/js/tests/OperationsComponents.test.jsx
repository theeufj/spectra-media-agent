import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Dropdown from '@/Components/Dropdown';
import FormErrorSummary from '@/Components/FormErrorSummary';
import NotificationBell from '@/Components/NotificationBell';
import SupportChat from '@/Components/SupportChat';

const state = vi.hoisted(() => ({
    props: { auth: { user: { id: 1, name: 'Alex', active_customer: { id: 1 } } } },
    polling: { data: null, error: null },
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    usePage: () => ({ props: state.props }),
    router: { visit: vi.fn() },
}));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => state.polling }));

const response = (body, status = 200) => ({ ok: status < 400, status, text: async () => JSON.stringify(body) });
beforeEach(() => {
    state.polling = { data: null, error: null };
    state.props = { auth: { user: { id: 1, name: 'Alex', active_customer: { id: 1 } } } };
    vi.stubGlobal('fetch', vi.fn());
    HTMLElement.prototype.scrollTo = vi.fn();
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

describe('accessible operational interactions', () => {
    it('announces a dropdown state and restores focus on Escape', async () => {
        render(<Dropdown><Dropdown.Trigger><button>Business knowledge</button></Dropdown.Trigger><Dropdown.Content><Dropdown.Link href="/knowledge-base">Knowledge base</Dropdown.Link></Dropdown.Content></Dropdown>);
        const trigger = screen.getByRole('button', { name: 'Business knowledge' });
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        fireEvent.keyDown(trigger, { key: 'ArrowDown' });
        await waitFor(() => expect(screen.getByRole('link', { name: 'Knowledge base' })).toHaveFocus());
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(trigger).toHaveFocus();
    });

    it('focuses a persistent error summary and links array errors to the relevant field', () => {
        render(<><label htmlFor="keywords">Keywords</label><textarea id="keywords" /><FormErrorSummary errors={{ 'keywords.0': 'Each term must be less than 200 characters.' }} labels={{ keywords: 'Keywords' }} /></>);
        expect(screen.getByRole('alert')).toHaveFocus();
        fireEvent.click(screen.getByRole('button', { name: /Keywords: Each term/ }));
        expect(screen.getByLabelText('Keywords')).toHaveFocus();
    });

    it('requests browser alert permission only from the explicit opt-in', async () => {
        const requestPermission = vi.fn().mockResolvedValue('granted');
        vi.stubGlobal('Notification', { permission: 'default', requestPermission });
        state.polling = { data: { notifications: [], unread_count: 0 }, error: null };
        render(<NotificationBell />);
        fireEvent.click(document.body);
        expect(requestPermission).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: /Notifications/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Enable browser alerts' }));
        await waitFor(() => expect(requestPermission).toHaveBeenCalledTimes(1));
        expect(await screen.findByText('Browser alerts enabled.')).toBeInTheDocument();
    });

    it('keeps an unread notification visible when the server refuses a read', async () => {
        state.polling = { data: { notifications: [{ id: 'uuid', title: 'Payment needs attention', message: 'Update billing', created_at: '2026-10-03T00:00:00Z' }], unread_count: 1 }, error: null };
        fetch.mockResolvedValue(response({ message: 'Unavailable' }, 503));
        render(<NotificationBell />);
        fireEvent.click(screen.getByRole('button', { name: /Notifications/ }));
        fireEvent.click(screen.getByRole('button', { name: /Payment needs attention/ }));
        await screen.findByRole('alert');
        expect(screen.getByRole('button', { name: /Payment needs attention/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Notifications \(1 unread\)/ })).toBeInTheDocument();
    });
});

describe('saved support conversations', () => {
    it('loads saved history and preserves an unsent draft when sending fails', async () => {
        fetch.mockResolvedValueOnce(response({ ticket_id: 82, messages: [{ role: 'customer', text: 'Where is my invoice?' }, { role: 'assistant', text: 'The team has your question.' }] })).mockResolvedValue(response({ message: 'Unavailable' }, 503));
        render(<SupportChat />);
        fireEvent.click(screen.getByRole('button', { name: 'Open support chat' }));
        expect(await screen.findByText('Where is my invoice?')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open ticket #82' })).toHaveAttribute('href', '/__route__/support-tickets.show/82');
        fireEvent.change(screen.getByRole('textbox', { name: 'Your support message' }), { target: { value: 'Please send the invoice.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByRole('alert');
        expect(screen.getByRole('textbox', { name: 'Your support message' })).toHaveValue('Please send the invoice.');
        expect(screen.getAllByText('Please send the invoice.')).toHaveLength(1);
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({ ticket_id: 82, message: 'Please send the invoice.' });
    });

    it('restores a conversation after remounting and returns focus when dismissed', async () => {
        fetch.mockResolvedValue(response({ ticket_id: 82, messages: [{ role: 'customer', text: 'Saved question' }] }));
        const first = render(<SupportChat />);
        fireEvent.click(screen.getByRole('button', { name: 'Open support chat' }));
        await screen.findByText('Saved question');
        first.unmount();
        render(<SupportChat />);
        const trigger = screen.getByRole('button', { name: 'Open support chat' });
        fireEvent.click(trigger);
        await screen.findByText('Saved question');
        await act(async () => fireEvent.keyDown(document, { key: 'Escape' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(trigger).toHaveFocus();
        expect(fetch).toHaveBeenCalledTimes(2);
    });
});
