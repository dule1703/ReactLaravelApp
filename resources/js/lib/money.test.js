import { describe, expect, it } from 'vitest';
import { formatMoney } from './money';

// Intl uses a non-breaking space before the currency sign; normalise it for comparison.
const fmt = (cents) => formatMoney(cents).replace(/\s/g, ' ');

describe('formatMoney', () => {
    it('formats cents with Serbian separators and euro sign', () => {
        expect(fmt(1234567)).toBe('12.345,67 €');
    });

    it('formats zero and small amounts', () => {
        expect(fmt(0)).toBe('0,00 €');
        expect(fmt(5)).toBe('0,05 €');
    });

    it('formats negative amounts', () => {
        expect(fmt(-1999)).toBe('-19,99 €');
    });

    it('rejects non-integer amounts', () => {
        expect(() => formatMoney(10.5)).toThrow(TypeError);
    });
});
