import { describe, expect, it } from 'vitest';
import { parseCollateral, budgetCents } from '../contracts/campaign';

describe('campaign transport contracts', () => {
    it('rejects an incomplete poll instead of replacing available creative', () => {
        expect(() => parseCollateral({message: 'Session expired'})).toThrow();
        const value = {adCopy: null, imageCollaterals: [], videoCollaterals: []};
        expect(parseCollateral(value)).toEqual({ ...value, collateralErrors: [] });
        expect(parseCollateral({ ...value, collateralErrors: { image: 'Provider unavailable' }, generationPending: false })).toMatchObject({ collateralErrors: [{ message: 'Provider unavailable' }], generationPending: false });
    });
    it('converts decimal budgets without truncation or non-finite values', () => {
        expect(budgetCents('12.34')).toBe(1234);
        expect(budgetCents('0.29')).toBe(29);
        for (const value of ['-1', 'NaN', 'Infinity', '10.999', '']) {
            expect(() => budgetCents(value)).toThrow();
        }
    });
});
