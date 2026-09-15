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

/**
 * Arriving at the collateral page before any creative exists.
 *
 * The auto-advance matched on the sum of all three counts, and ad copy is
 * written first and fastest — so it fired the moment the copy landed and
 * dropped the customer onto a page with no pictures on it. They came to see
 * the ads.
 */
const IMAGE_WAIT_MS = 5 * 60 * 1000;

const stillGeneratingImages = (s) => {
    if ((s.image_collaterals_count || 0) > 0) return false;

    const signedOff = new Date(s.signed_off_at).getTime();

    return Number.isFinite(signedOff) && (Date.now() - signedOff) < IMAGE_WAIT_MS;
};

const readyForCollateral = (strategies) =>
    strategies.find(s => s.signed_off_at && (s.image_collaterals_count || 0) > 0)
    || strategies.find(s => s.signed_off_at
        && ! stillGeneratingImages(s)
        && ((s.ad_copies_count || 0) + (s.video_collaterals_count || 0)) > 0);

const justNow = () => new Date().toISOString();
const longAgo = () => new Date(Date.now() - 10 * 60 * 1000).toISOString();

describe('when to leave the strategy page', () => {
    it('does not leave on ad copy alone while images are still coming', () => {
        const ready = readyForCollateral([
            { uuid: 'a', signed_off_at: justNow(), ad_copies_count: 1, image_collaterals_count: 0 },
        ]);

        expect(ready).toBeUndefined();
    });

    it('leaves as soon as the first picture lands', () => {
        const ready = readyForCollateral([
            { uuid: 'a', signed_off_at: justNow(), ad_copies_count: 1, image_collaterals_count: 3 },
        ]);

        expect(ready?.uuid).toBe('a');
    });

    it('still leaves for a strategy that will never have images', () => {
        /*
           A video-only strategy, or one whose image allowance is spent, has
           nothing more coming — waiting for a picture there would strand the
           customer watching for something that is not on its way. Ten minutes
           after sign-off, nothing more is arriving.
        */
        const ready = readyForCollateral([
            { uuid: 'a', signed_off_at: longAgo(), ad_copies_count: 1, image_collaterals_count: 0 },
        ]);

        expect(ready?.uuid).toBe('a');
    });
});

/**
 * The wait, the poll and the jump must agree.
 *
 * They did not, and the disagreement froze the page. The wait and the poll
 * both treated ad copy as "something arrived" while the jump insisted on a
 * picture — so the moment ad copy landed, which is first and fastest, polling
 * stopped and the jump refused. Campaign 48 sat on "Generating your
 * collateral... this usually takes 1-2 minutes" over nine images that had
 * finished two minutes earlier, with nothing left running to notice.
 */
const stillWaiting = (s) => Boolean(s.signed_off_at)
    && ((s.image_collaterals_count || 0) === 0)
    && (stillGeneratingImages(s) || ((s.ad_copies_count || 0) + (s.video_collaterals_count || 0)) === 0);

describe('the wait, the poll and the jump', () => {
    it('keeps waiting when only the ad copy has arrived', () => {
        // The exact state that froze campaign 48.
        const s = { signed_off_at: justNow(), ad_copies_count: 1, image_collaterals_count: 0 };

        expect(stillWaiting(s)).toBe(true);
        expect(readyForCollateral([s])).toBeUndefined();
    });

    it('stops waiting the moment a picture lands', () => {
        const s = { signed_off_at: justNow(), ad_copies_count: 1, image_collaterals_count: 3 };

        expect(stillWaiting(s)).toBe(false);
        expect(readyForCollateral([s])).toBe(s);
    });

    it('gives up on images once the window has passed', () => {
        // Otherwise a strategy that will never have one waits for ever.
        const s = { signed_off_at: longAgo(), ad_copies_count: 1, image_collaterals_count: 0 };

        expect(stillWaiting(s)).toBe(false);
        expect(readyForCollateral([s])).toBe(s);
    });

    it('never stops polling before the jump would fire', () => {
        /*
           The invariant the freeze violated: if the page would still not
           navigate, something must still be watching for the thing it is
           waiting on.
        */
        const cases = [
            { signed_off_at: justNow(), ad_copies_count: 0, image_collaterals_count: 0 },
            { signed_off_at: justNow(), ad_copies_count: 1, image_collaterals_count: 0 },
            { signed_off_at: justNow(), ad_copies_count: 1, image_collaterals_count: 2 },
            { signed_off_at: longAgo(), ad_copies_count: 1, image_collaterals_count: 0 },
            { signed_off_at: longAgo(), ad_copies_count: 0, image_collaterals_count: 0 },
        ];

        cases.forEach((s) => {
            const willNavigate = Boolean(readyForCollateral([s]));

            if (! willNavigate) {
                expect(stillWaiting(s)).toBe(true);
            }
        });
    });
});
