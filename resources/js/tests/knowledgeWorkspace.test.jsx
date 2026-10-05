import React, { useState } from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import Index from '@/Pages/KnowledgeBase/Index';
import Create from '@/Pages/KnowledgeBase/Create';
import Show from '@/Pages/KnowledgeBase/Show';
import { router } from '@inertiajs/react';
import { fetchJson } from '@/utils/http';

vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => ({ data: null, failureStreak: 0 }) }));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ children }) => <main>{children}</main> }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href, ...props }) => <a href={href} {...props}>{children}</a>,
    router: { reload: vi.fn(), get: vi.fn() },
    useForm: initial => {
        const [data, update] = useState(initial);
        return { data, errors: {}, processing: false, setData: (name, value) => update(old => typeof name === 'object' ? name : ({ ...old, [name]: value })), post: vi.fn() };
    },
}));
global.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`;
afterEach(() => { cleanup(); vi.clearAllMocks(); vi.useRealTimers(); });
const props = { customer: { id: 1, name: 'Example business' }, knowledgeBases: { data: [] }, health: { readable: 1, ready: 0, total: 1 } };
const evidence = { kb_id: 12, source_name: 'Enterprise plan', source_version: 3, position: 4, chunk: 'SOC 2 support is included.', url: 'https://example.com/enterprise' };

describe('knowledge workspace', () => {
    it('shows readable evidence during incomplete preparation and links to its exact source version', async () => {
        fetchJson.mockResolvedValue({ results: [evidence], customer_id: 1 });
        render(<Index {...props} />);
        fireEvent.change(screen.getByLabelText('Business question'), { target: { value: 'SOC 2?' } });
        fireEvent.click(screen.getByRole('button', { name: 'Find evidence' }));
        expect(await screen.findByText('SOC 2 support is included.')).toBeInTheDocument();
        expect(screen.getByText('Version 3 · Passage 5')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View source and context' })).toHaveAttribute('href', expect.stringContaining('"version":3'));
        expect(screen.queryByText(/confidence:|% confident/i)).not.toBeInTheDocument();
    });

    it('discards a previous business search after the active business changes', async () => {
        let finish;
        fetchJson.mockReturnValue(new Promise(resolve => { finish = resolve; }));
        const view = render(<Index {...props} />);
        fireEvent.change(screen.getByLabelText('Business question'), { target: { value: 'SOC 2?' } });
        fireEvent.click(screen.getByRole('button', { name: 'Find evidence' }));
        view.rerender(<Index {...props} customer={{ id: 2, name: 'Different business' }} />);
        await act(() => finish({ results: [evidence], customer_id: 1 }));
        expect(screen.queryByText(evidence.chunk)).not.toBeInTheDocument();
        expect(screen.getByLabelText('Business question')).toHaveValue('');
    });

    it('offers recovery for no evidence and keeps provider failures visible', async () => {
        fetchJson.mockResolvedValueOnce({ results: [] });
        render(<Index {...props} />);
        fireEvent.change(screen.getByLabelText('Business question'), { target: { value: 'Unsupported policy?' } });
        fireEvent.click(screen.getByRole('button', { name: 'Find evidence' }));
        expect(await screen.findByRole('link', { name: 'Add missing information' })).toBeInTheDocument();
        fetchJson.mockRejectedValueOnce(new Error('Unavailable'));
        fireEvent.click(screen.getByRole('button', { name: 'Find evidence' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Your sources are retained');
    });

    it('renders only fields for the chosen input mode and accepts keyboard file selection', () => {
        render(<Create customer={{ name: 'Example', website: 'https://example.com' }} sourceLimit={3} sourceCount={1} />);
        expect(screen.getByLabelText('Website address')).toHaveValue('https://example.com');
        expect(screen.queryByLabelText('Business information')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Write a note/ }));
        expect(screen.getByLabelText('Business information')).toBeInTheDocument();
        expect(screen.queryByLabelText('Website address')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Document/ }));
        const input = screen.getByLabelText('Choose a document or drop it here');
        fireEvent.change(input, { target: { files: [new File(['text'], 'facts.txt', { type: 'text/plain' })] } });
        expect(screen.getByText(/Selected: facts.txt/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add source' })).toBeEnabled();
    });

    it('shows completed replacement content under its new version after partial live updates', () => {
        vi.useFakeTimers();
        const source = { id: 12, title: 'Refund policy', source_type: 'url', url: 'https://example.com/refunds', source_version: 1, content: 'Refunds are available for 30 days.', processing_status: 'indexing' };
        const finished = { source: { ...source, source_version: 2, content: 'Refunds are available for 14 days.', processing_status: 'ready' }, version: 2, versions: [2, 1], passages: [{ content: 'Refunds are available for 14 days.' }], retrievals: [], fileRevision: 'revision' };
        let update;
        function LiveSource() {
            const [page, setPage] = useState({ source, version: 1, versions: [1], customer: props.customer });
            update = setPage;
            return <Show {...page} />;
        }
        router.reload.mockImplementation(({ only }) => update(current => ({ ...current, ...Object.fromEntries(only.filter(key => key in finished).map(key => [key, finished[key]])) })));
        render(<LiveSource />);
        expect(screen.getByText('Refunds are available for 30 days.')).toBeInTheDocument();

        act(() => vi.advanceTimersByTime(8000));

        expect(screen.getByText(/Source version 2/)).toBeInTheDocument();
        expect(screen.getByText('Readable source content')).toBeInTheDocument();
        expect(screen.getByText('Refunds are available for 14 days.')).toBeInTheDocument();
        expect(screen.queryByText('Refunds are available for 30 days.')).not.toBeInTheDocument();
    });
});
