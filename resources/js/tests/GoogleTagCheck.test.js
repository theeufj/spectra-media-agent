import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { resolve } from 'node:path';
import { expect, it, vi } from 'vitest';

const script = readFileSync(resolve('public/google-tag-check.js'), 'utf8');

it('relays only the reviewed Google transaction and paid value to the exact opener and returns its acknowledgement', async () => {
    const host = 'https://app.example';
    const review = {postMessage: vi.fn()};
    const listeners = new Map();
    const window = {opener: review, addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name)};
    const now = Date.now();
    const prefix = 'https://www.googleadservices.com/pagead/conversion/131/?';
    const queries = [
        'label=other&oid=order-3&value=59&currency_code=USD',
        'label=pay&oid=order-9&value=59&currency_code=USD',
        'label=pay&oid=order-3&value=50&currency_code=USD',
        'label=pay&oid=order-3&value=59&currency_code=EUR',
        'label=pay&oid=order-3&value=59&currency_code=USD',
    ];
    const entries = queries.map(query => ({ name: prefix + query, startTime: 0 }));
    entries.unshift({name: 'https://googleadservices.com.untrusted.test/pagead/conversion/131/?' + queries.at(-1), startTime: 0});
    runInNewContext(script, {window, document: {currentScript: {src: host + '/google-tag-check.js'}}, URL, Date, setTimeout, clearTimeout,
        location: {href: 'https://merchant.example/confirmed'}, performance: {timeOrigin: now, getEntriesByType: () => entries},
        PerformanceObserver: class { observe() {} disconnect() {} }});
    await expect(window.SiteToSpendGoogleTest.watch({goal: 'purchase', consent: 'denied'})).resolves.toBe(null);
    const delivered = window.SiteToSpendGoogleTest.watch({goal: 'purchase', eventId: 'order-3', amount: '59.00', currency: 'USD', consent: 'granted'});
    const data = {type: 'sitetospend-test-context', challenge: 'uuid', goal: 'purchase', send_to: 'AW-131/pay'};
    const message = listeners.get('message');
    message({origin: 'https://bad.example', source: review, data});
    message({origin: host, source: {}, data});
    expect(review.postMessage).toHaveBeenCalledTimes(1);
    message({origin: host, source: review, data});
    const observation = review.postMessage.mock.calls.at(-1);
    expect(observation[0].receipt).toMatchObject({event_id: 'order-3', send_to: 'AW-131/pay', amount: '59.00', currency: 'USD'});
    expect(observation[1]).toBe(host);
    const response = {type: 'sitetospend-test-response', challenge: 'uuid', goal: 'purchase', event_id: 'order-3', accepted: true};
    message({origin: host, source: review, data: response});
    await expect(delivered).resolves.toMatchObject({event_id: 'order-3'});
});
