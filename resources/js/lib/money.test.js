import { describe, expect, it } from 'vitest';
import parseCases from '../../../tests/fixtures/money-parse-cases.json';
import { formatMoney, formatMoneyOrDash, formatRateBp, parseEuros, parsePercentBp, roundToEuro } from './money';

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

describe('parseEuros / parsePercentBp (shared fixture cases)', () => {
    it.each(parseCases.euros.valid)('parses "%s" as %i cents', (input, cents) => {
        expect(parseEuros(input)).toBe(cents);
    });

    it.each(parseCases.euros.invalid)('rejects "%s"', (input) => {
        expect(parseEuros(input)).toBeNull();
    });

    it.each(parseCases.euros.signedValid)('parses signed "%s" as %i cents', (input, cents) => {
        expect(parseEuros(input, { signed: true })).toBe(cents);
    });

    it.each(parseCases.euros.signedInvalid)('rejects signed "%s"', (input) => {
        expect(parseEuros(input, { signed: true })).toBeNull();
    });

    it.each(parseCases.percent.valid)('parses "%s" as %i basis points', (input, bp) => {
        expect(parsePercentBp(input)).toBe(bp);
    });

    it.each(parseCases.percent.invalid)('rejects percent "%s"', (input) => {
        expect(parsePercentBp(input)).toBeNull();
    });

    it.each(parseCases.percent.signedValid)('parses signed percent "%s" as %i', (input, bp) => {
        expect(parsePercentBp(input, { signed: true })).toBe(bp);
    });

    it.each(parseCases.percent.signedInvalid)('rejects signed percent "%s"', (input) => {
        expect(parsePercentBp(input, { signed: true })).toBeNull();
    });
});

describe('roundToEuro', () => {
    it('rounds half up to a whole euro', () => {
        expect(roundToEuro(149)).toBe(100);
        expect(roundToEuro(150)).toBe(200);
        expect(roundToEuro(200)).toBe(200);
    });
});

describe('formatMoneyOrDash', () => {
    it('shows a dash for an unknown amount and formats a known one', () => {
        expect(formatMoneyOrDash(null)).toBe('-');
        expect(formatMoneyOrDash(undefined)).toBe('-');
        expect(formatMoneyOrDash(0)).toBe(formatMoney(0));
        expect(formatMoneyOrDash(123456)).toBe(formatMoney(123456));
    });

    it('still refuses a non-integer amount', () => {
        expect(() => formatMoneyOrDash(1.5)).toThrow(TypeError);
    });
});

describe('formatRateBp', () => {
    it('formats basis points as a percentage', () => {
        expect(formatRateBp(2000)).toBe('20');
        expect(formatRateBp(750)).toBe('7,5');
        expect(formatRateBp(0)).toBe('0');
    });

    it('rejects a non-integer rate', () => {
        expect(() => formatRateBp(20.5)).toThrow(TypeError);
    });
});
