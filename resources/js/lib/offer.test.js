import { describe, expect, it } from 'vitest';
import cases from '../../../tests/fixtures/offer-calc-cases.json';
import { calculateOffer, MAX_OPTIONS, MAX_PRICE_CENTS, MAX_QUANTITY, MAX_TOTAL_NET_CENTS, OfferCalculationError } from './offer';
import { vatAmount } from './vat';

describe('calculateOffer (shared fixture cases)', () => {
    it.each(cases.valid)('$name', ({ vat_rate_bp, items, expected }) => {
        expect(calculateOffer(items, vat_rate_bp)).toEqual(expected);
    });
});

describe('calculateOffer rejects bad input (shared fixture cases)', () => {
    it.each(cases.invalid)('$name', ({ vat_rate_bp, items, category }) => {
        let error;

        try {
            calculateOffer(items, vat_rate_bp);
        } catch (e) {
            error = e;
        }

        expect(error).toBeInstanceOf(OfferCalculationError);
        expect(error.category).toBe(category);
    });
});

describe('calculateOffer guards', () => {
    it('treats -0 as 0', () => {
        const result = calculateOffer([{ version_price_cents: -0, quantity: 1, options: [{ price_cents: -0 }] }], 2000);

        expect(Object.is(result.items[0].line_net_cents, 0)).toBe(true);
        expect(Object.is(result.total_net_cents, 0)).toBe(true);
        expect(Object.is(result.vat_cents, 0)).toBe(true);
    });

    it('rejects NaN, Infinity and unsafe integers', () => {
        for (const bad of [NaN, Infinity, Number.MAX_SAFE_INTEGER + 1]) {
            expect(() => calculateOffer([{ version_price_cents: bad, quantity: 1, options: [] }], 2000)).toThrow(OfferCalculationError);
        }
    });

    it('rejects items that are not a list', () => {
        expect(() => calculateOffer({}, 2000)).toThrow(OfferCalculationError);
    });

    it('keeps the limits in step with the server', () => {
        expect([MAX_PRICE_CENTS, MAX_QUANTITY, MAX_OPTIONS, MAX_TOTAL_NET_CENTS]).toEqual([1e9, 999, 200, 1e11]);
    });

    it('stays within safe integers at the worst case before the total limit is checked', () => {
        const worst = (MAX_PRICE_CENTS + MAX_OPTIONS * MAX_PRICE_CENTS) * MAX_QUANTITY;

        expect(Number.isSafeInteger(worst)).toBe(true);
    });

    it('computes VAT once on the total through vat.js', () => {
        const result = calculateOffer([{ version_price_cents: 3, quantity: 1, options: [] }, { version_price_cents: 3, quantity: 1, options: [] }], 2000);

        expect(result.vat_cents).toBe(vatAmount(6, 2000));
    });
});
