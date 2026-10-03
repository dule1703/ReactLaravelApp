import { describe, expect, it } from 'vitest';
import cases from '../../../tests/fixtures/vat-cases.json';
import { grossFromNet, netFromGross, vatAmount } from './vat';

describe('netFromGross (shared fixture cases)', () => {
    it.each(cases.netFromGross)('rate $rate_bp: gross $gross -> net $net', ({ rate_bp, gross, net }) => {
        expect(netFromGross(gross, rate_bp)).toBe(net);
    });
});

describe('grossFromNet (shared fixture cases)', () => {
    it.each(cases.grossFromNet)('rate $rate_bp: net $net -> gross $gross', ({ rate_bp, net, gross }) => {
        expect(grossFromNet(net, rate_bp)).toBe(gross);
    });

    it('is net plus the rounded VAT amount', () => {
        expect(vatAmount(2500000, 2000)).toBe(500000);
        expect(grossFromNet(2500000, 2000)).toBe(3000000);
    });
});

describe('round trip', () => {
    it('gross -> net -> gross is the same for every whole euro at the fixture rate', () => {
        const { rate_bp, from_euro, to_euro } = cases.roundTrip;

        for (let euro = from_euro; euro <= to_euro; euro++) {
            const gross = euro * 100;

            expect(grossFromNet(netFromGross(gross, rate_bp), rate_bp)).toBe(gross);
        }
    });
});

describe('input checks', () => {
    it('rejects non-integers', () => {
        expect(() => netFromGross(10.5, 2000)).toThrow(TypeError);
        expect(() => grossFromNet(10, 20.5)).toThrow(TypeError);
    });
});
