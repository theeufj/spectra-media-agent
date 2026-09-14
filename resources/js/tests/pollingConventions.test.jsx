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

/**
 * Mirrors the two waiting conditions added to Campaigns/Show.jsx and
 * KnowledgeBase/Index.jsx.
 */
const items = (s) => (s.ad_copies_count || 0) + (s.image_collaterals_count || 0) + (s.video_collaterals_count || 0);
const readyStrategy = (strategies) => strategies.find(s => s.signed_off_at && items(s) > 0);
const anyProcessing = (rows) => rows.some(kb => ! kb.content);

describe('pages that show a waiting state', () => {
    it('sends you to the collateral once it exists, rather than making you click', () => {
        /*
           Sign off, a modal, this page, "Generating your collateral", and then
           a second click to go and look at it. Standing there was only ever
           about seeing the creative — three separate pages had this shape, and
           it is what makes a two-minute wait feel broken rather than busy.
        */
        const ready = readyStrategy([
            { uuid: 'a', signed_off_at: '2026-09-14', image_collaterals_count: 0 },
            { uuid: 'b', signed_off_at: '2026-09-14', image_collaterals_count: 9 },
        ]);

        expect(ready?.uuid).toBe('b');
    });

    it('does not send you anywhere when nothing has arrived', () => {
        expect(readyStrategy([{ uuid: 'a', signed_off_at: '2026-09-14', image_collaterals_count: 0 }])).toBeUndefined();
    });

    it('keeps the knowledge base refreshing while a page is still empty', () => {
        // Crawled rows arrive with no content and fill in behind the scenes.
        expect(anyProcessing([{ content: 'Real text' }, { content: null }])).toBe(true);
    });

    it('stops refreshing the knowledge base once every page has content', () => {
        expect(anyProcessing([{ content: 'Real text' }, { content: 'More text' }])).toBe(false);
    });
});
