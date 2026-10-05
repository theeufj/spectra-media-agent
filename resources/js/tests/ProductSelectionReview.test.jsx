import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render } from '@testing-library/react';
import ProductSelection from '@/Pages/Campaigns/ProductSelection';
import { fetchJson } from '@/utils/http';
vi.mock('@/utils/http', () => ({ fetchJson: vi.fn() }));

afterEach(() => { vi.useRealTimers(); vi.clearAllMocks(); });

describe('saved campaign destination', () => {
    it('restores a selected page and keeps its destination while lookup fails', async () => {
        vi.useFakeTimers();
        fetchJson.mockRejectedValue(new Error('offline'));
        const { getByRole, getByText } = render(<ProductSelection customerUuid="customer-a" selectedPages={[45]} destinationUrl="https://business.example/repair" onSelectionChange={vi.fn()} />);
        await act(async () => { await vi.advanceTimersByTimeAsync(300); });
        expect(getByRole('combobox')).toHaveValue('https://business.example/repair');
        expect(getByText('Saved destination: https://business.example/repair')).toBeInTheDocument();
        expect(getByRole('alert')).toHaveTextContent('We could not load website pages');
        fetchJson.mockResolvedValue({ data: [{ id: 45, title: 'Repair services', url: 'https://business.example/repair' }] });
        fireEvent.click(getByRole('button', { name: 'Retry' }));
        await act(async () => { await vi.advanceTimersByTimeAsync(300); });
        expect(fetchJson).toHaveBeenCalledTimes(3);
    });

    it('ignores a stale search result after a newer search replaces it', async () => {
        vi.useFakeTimers();
        let finishOld;
        fetchJson.mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve; })).mockResolvedValue({ data: [{ id: 2, title: 'New result', url: 'https://business.example/new' }] });
        const { getByRole, queryByText } = render(<ProductSelection customerUuid="customer-a" onSelectionChange={vi.fn()} />);
        await act(async () => { await vi.advanceTimersByTimeAsync(300); });
        fireEvent.change(getByRole('combobox'), { target: { value: 'new' } });
        await act(async () => { await vi.advanceTimersByTimeAsync(300); });
        await act(async () => { finishOld({ data: [{ id: 1, title: 'Stale result' }] }); });
        expect(fetchJson.mock.calls[0][1].signal.aborted).toBe(true);
        expect(queryByText('Stale result')).toBeNull();
    });
});
