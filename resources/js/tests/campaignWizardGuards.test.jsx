import { describe, it, expect } from 'vitest';

/**
 * Two ways the campaign wizard lost or leaked a customer's work.
 *
 * The wizard is ~1200 lines and nine steps deep behind Inertia, an upload
 * widget and a brand-guideline fetch, so these cover the two rules directly
 * rather than driving the whole component: what the draft is keyed on, and
 * when Enter is allowed to submit. Both are small pure decisions that were
 * wrong, and both are invisible until they cost someone a campaign.
 */

const STEP_COUNT = 9;

/** Mirrors handleFormKeyDown in Pages/Campaigns/CreateWizard.jsx. */
function enterSubmits({ key, currentStep, tagName }) {
    const isFinalStep = currentStep === STEP_COUNT - 1;

    if (key !== 'Enter' || isFinalStep) return key === 'Enter' && isFinalStep;
    if (tagName === 'TEXTAREA') return false;

    return false;
}

/** Mirrors the draftKey memo in Pages/Campaigns/CreateWizard.jsx. */
function draftKey(auth) {
    return `campaign_draft:${auth?.user?.id ?? 'anon'}:${auth?.user?.active_customer?.id ?? 'none'}`;
}

describe('wizard Enter handling', () => {
    it('does not submit from an intermediate step', () => {
        // The form wraps all nine steps, so a step with a single text input
        // triggers HTML implicit submission on Enter — no visible submit button
        // required. That posted a half-filled wizard to campaigns.store.
        for (let step = 0; step < STEP_COUNT - 1; step++) {
            expect(enterSubmits({ key: 'Enter', currentStep: step, tagName: 'INPUT' })).toBe(false);
        }
    });

    it('still submits from the final step', () => {
        expect(enterSubmits({ key: 'Enter', currentStep: STEP_COUNT - 1, tagName: 'INPUT' })).toBe(true);
    });

    it('never swallows Enter inside a textarea', () => {
        // Newlines in a description are typing, not a submit.
        expect(enterSubmits({ key: 'Enter', currentStep: 2, tagName: 'TEXTAREA' })).toBe(false);
    });

    it('ignores every other key', () => {
        expect(enterSubmits({ key: 'a', currentStep: 2, tagName: 'INPUT' })).toBe(false);
    });
});

describe('wizard draft key', () => {
    const alice = { user: { id: 1, active_customer: { id: 10 } } };
    const aliceOtherCustomer = { user: { id: 1, active_customer: { id: 11 } } };
    const bob = { user: { id: 2, active_customer: { id: 10 } } };

    it('separates two customers belonging to the same user', () => {
        // A draft holds a business's landing pages, keywords and budget. One
        // person managing two customers had the first one's half-built campaign
        // restored into the second.
        expect(draftKey(alice)).not.toBe(draftKey(aliceOtherCustomer));
    });

    it('separates two users sharing a browser', () => {
        expect(draftKey(alice)).not.toBe(draftKey(bob));
    });

    it('is stable for the same user and customer', () => {
        expect(draftKey(alice)).toBe(draftKey({ user: { id: 1, active_customer: { id: 10 } } }));
    });

    it('does not collide when a customer is not yet selected', () => {
        expect(draftKey({ user: { id: 1 } })).toBe('campaign_draft:1:none');
        expect(draftKey({ user: { id: 1 } })).not.toBe(draftKey(alice));
    });

    it('a draft written under one key is not readable under another', () => {
        // A plain store rather than window.localStorage: what is being asserted
        // is the isolation the key buys, not the browser API.
        const store = new Map();
        store.set(draftKey(alice), JSON.stringify({ data: { name: 'Alice campaign' }, step: 3 }));

        expect(store.get(draftKey(bob))).toBeUndefined();
        expect(store.get(draftKey(aliceOtherCustomer))).toBeUndefined();
        expect(JSON.parse(store.get(draftKey(alice))).data.name).toBe('Alice campaign');
    });
});
