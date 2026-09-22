import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import KeywordSelector from '@/Components/KeywordSelector';

const response = (status, data) => ({ ok: status < 400, status, text: async () => data === null ? '' : JSON.stringify(data) });
const result = { keywords: [{ text: 'real estate advertising', match_type: 'PHRASE' }], negative_keywords: ['jobs'] };

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="old-page-token">';
    document.cookie = 'XSRF-TOKEN=current-cookie; Path=/';
    vi.stubGlobal('fetch', vi.fn());
});
afterEach(() => {
    cleanup();
    document.head.innerHTML = '';
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/';
    vi.unstubAllGlobals();
});

function start() {
    const onChange = vi.fn();
    render(<KeywordSelector onChange={onChange} landingPage="https://realpropertyads.com" />);
    fireEvent.change(screen.getByPlaceholderText(/e.g. plumber/), { target: { value: 'real estate advertising' } });
    fireEvent.click(screen.getByRole('button', { name: 'Research Keywords' }));
    return onChange;
}

describe('keyword research session recovery', () => {
    it('refreshes once on a CSRF rejection and keeps the research inputs and results', async () => {
        fetch.mockResolvedValueOnce(response(419, { message: 'CSRF token mismatch.' }))
            .mockImplementationOnce(async () => {
                document.cookie = 'XSRF-TOKEN=refreshed-cookie; Path=/';
                return response(204, null);
            })
            .mockResolvedValueOnce(response(200, result));
        const onChange = start();
        await waitFor(() => expect(onChange).toHaveBeenCalledTimes(1));
        expect(fetch.mock.calls.map(([url]) => url)).toEqual([
            '/keywords/inline-research', '/sanctum/csrf-cookie', '/keywords/inline-research',
        ]);
        expect(fetch.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBe('current-cookie');
        expect(fetch.mock.calls[2][1].headers['X-XSRF-TOKEN']).toBe('refreshed-cookie');
        expect(fetch.mock.calls[2][1].body).toBe(fetch.mock.calls[0][1].body);
        expect(onChange.mock.calls[0][0][0]).toMatchObject(result.keywords[0]);
        expect(screen.getByPlaceholderText(/e.g. plumber/)).toHaveValue('real estate advertising');
        expect(screen.queryByText('CSRF token mismatch.')).toBeNull();
    });

    it.each([401, 419])('stops after a failed refresh/retry with status %s and preserves the draft', async (status) => {
        fetch.mockResolvedValueOnce(response(419, {}))
            .mockResolvedValueOnce(response(204, null))
            .mockResolvedValueOnce(response(status, {}));
        const onChange = start();
        expect(await screen.findByText(/Sign in again in another tab/)).toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(3);
        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByPlaceholderText(/e.g. plumber/)).toHaveValue('real estate advertising');
        expect(screen.getByPlaceholderText('https://example.com')).toHaveValue('https://realpropertyads.com');
        expect(screen.getByRole('button', { name: 'Research Keywords' })).toBeEnabled();
    });

    it('shows validation failures without refreshing or replaying the request', async () => {
        fetch.mockResolvedValue(response(422, { message: 'The given data was invalid.', errors: { landing_page: ['Use a valid website URL.'] } }));
        start();
        expect(await screen.findByText('Use a valid website URL.')).toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('does not replay server errors that could have already run the research', async () => {
        fetch.mockResolvedValue(response(500, { error: 'Keyword research did not complete.' }));
        start();
        expect(await screen.findByText('Keyword research did not complete.')).toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
