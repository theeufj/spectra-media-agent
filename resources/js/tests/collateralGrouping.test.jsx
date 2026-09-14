import { describe, it, expect } from 'vitest';
import { groupConcepts } from '@/utils/collateral';

const row = (id, concept_key, format, should_deploy = true) => ({ id, concept_key, format, should_deploy });

describe('grouping image collateral into concepts', () => {
    it('shows one card per photograph, not one per ad size', () => {
        // What campaign 40 actually held: 8 scenes stored as 24 rows.
        const images = [];
        for (let c = 0; c < 8; c++) {
            ['square', 'landscape', 'mrec'].forEach((f, i) => images.push(row(c * 3 + i, `c${c}`, f)));
        }

        expect(images).toHaveLength(24);
        expect(groupConcepts(images)).toHaveLength(8);
    });

    it('puts the square on the card, whatever order the rows arrive in', () => {
        const groups = groupConcepts([row(1, 'a', 'mrec'), row(2, 'a', 'square'), row(3, 'a', 'landscape')]);

        expect(groups[0].cover.id).toBe(2);
        expect(groups[0].ids).toEqual([1, 2, 3]);
    });

    it('keeps every size on the card so deploying one deploys them all', () => {
        const groups = groupConcepts([row(1, 'a', 'square'), row(2, 'a', 'landscape'), row(3, 'a', 'mrec')]);

        expect(groups[0].formats).toEqual(['square', 'landscape', 'mrec']);
    });

    it('reads a half-toggled concept as not deployed', () => {
        // Otherwise a green card would deploy two of its three sizes.
        const groups = groupConcepts([row(1, 'a', 'square', true), row(2, 'a', 'landscape', false)]);

        expect(groups[0].deployed).toBe(false);
    });

    it('never merges rows that predate concept keys', () => {
        /*
           Old rows carry no key. Grouping them together would put unrelated
           photographs behind one card — a worse error than showing each alone.
        */
        const groups = groupConcepts([row(1, null, 'square'), row(2, null, 'square'), row(3, null, 'landscape')]);

        expect(groups).toHaveLength(3);
    });

    it('survives an empty set', () => {
        expect(groupConcepts([])).toEqual([]);
        expect(groupConcepts(null)).toEqual([]);
    });
});
