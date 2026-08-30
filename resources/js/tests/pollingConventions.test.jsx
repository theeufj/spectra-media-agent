import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'fs';
import { join, relative } from 'path';

/**
 * Pages must poll through the hooks, not setInterval + raw fetch.
 *
 * The failure this prevents is silent: a hand-rolled poll whose only error
 * handling is console.error leaves a spinner turning forever when the endpoint
 * starts refusing — an expired session, a deploy mid-generation, a 403. The
 * user cannot tell a slow job from a dead page, and support gets "it just sat
 * there". Campaigns/Show and Campaigns/Collateral both did this on the signup
 * path, where the cost is an abandoned onboarding.
 *
 * usePolling skips overlapping requests and cleans up on unmount; useJobWatch
 * adds the phase distinction (done / failed / timeout / disconnected) a UI
 * needs to say which thing went wrong.
 */

const ROOT = join(process.cwd(), 'resources/js');

/**
 * Not yet migrated. Each still hand-rolls a poll, and each should move to the
 * hooks — but they are lower-stakes than the signup path, so they are recorded
 * here rather than blocking. Remove an entry when you migrate it; do not add
 * one to make a new page pass.
 */
const KNOWN_UNMIGRATED = [
    'Pages/Proposals/Show.jsx',
    'Components/NotificationBell.jsx',
];

function jsxFiles(dir) {
    const out = [];

    for (const entry of readdirSync(dir)) {
        if (entry === 'tests' || entry === 'node_modules') continue;

        const full = join(dir, entry);

        if (statSync(full).isDirectory()) {
            out.push(...jsxFiles(full));
        } else if (entry.endsWith('.jsx') || entry.endsWith('.js')) {
            out.push(full);
        }
    }

    return out;
}

describe('polling conventions', () => {
    const offenders = jsxFiles(ROOT)
        .map((file) => ({ file: relative(ROOT, file), source: readFileSync(file, 'utf8') }))
        // A poll is an interval that also talks to the server with raw fetch.
        // A bare setInterval driving a clock is not what this is about, and
        // neither is an interval calling router.reload() — Inertia handles an
        // expired session itself, which is the failure mode this guards.
        .filter(({ source }) => /setInterval\s*\(/.test(source) && /fetch\s*\(/.test(source))
        .map(({ file }) => file)
        .filter((file) => !KNOWN_UNMIGRATED.includes(file))
        .sort();

    it('no page polls the server with setInterval and raw fetch', () => {
        expect(offenders).toEqual([]);
    });

    it('the allow-list has no stale entries', () => {
        // A migrated page left on the list would quietly re-open the door.
        const stillHandRolled = KNOWN_UNMIGRATED.filter((file) => {
            const source = readFileSync(join(ROOT, file), 'utf8');

            return /setInterval\s*\(/.test(source) && /fetch\s*\(/.test(source);
        });

        expect(stillHandRolled).toEqual(KNOWN_UNMIGRATED);
    });
});
