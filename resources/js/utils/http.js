/**
 * Small fetch wrapper for the JSON endpoints that sit alongside Inertia.
 *
 * Pages were each hand-rolling `fetch -> response.json() -> setState` with their
 * own try/catch, and POST callers each re-read the CSRF meta tag — some without
 * a null guard, so a missing tag threw a TypeError instead of failing cleanly.
 *
 * Use Inertia's router for anything that navigates or submits a form; this is
 * only for JSON reads and small side-effect POSTs that stay on the page.
 */

export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

export class HttpError extends Error {
    constructor(response, body) {
        super(`Request failed with ${response.status}`);
        this.name = 'HttpError';
        this.status = response.status;
        this.body = body;
    }
}

/**
 * Fetch JSON, throwing HttpError on a non-2xx so callers can branch on status
 * instead of silently treating an error page as data.
 *
 * @param {string} url
 * @param {RequestInit & {json?: unknown}} [options]
 */
export async function fetchJson(url, options = {}) {
    const { json, headers, ...rest } = options;

    const init = {
        credentials: 'same-origin',
        ...rest,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(json !== undefined ? { 'Content-Type': 'application/json' } : {}),
            ...(rest.method && rest.method.toUpperCase() !== 'GET'
                ? { 'X-CSRF-TOKEN': csrfToken() }
                : {}),
            ...headers,
        },
    };

    if (json !== undefined) {
        init.body = JSON.stringify(json);
    }

    const response = await fetch(url, init);

    // 204 and empty bodies are valid successes with nothing to parse.
    const text = await response.text();
    let body = null;
    if (text) {
        try {
            body = JSON.parse(text);
        } catch {
            body = text;
        }
    }

    if (!response.ok) {
        throw new HttpError(response, body);
    }

    return body;
}
