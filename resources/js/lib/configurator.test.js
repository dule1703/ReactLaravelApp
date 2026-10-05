import { describe, expect, it } from 'vitest';
import {
    buildItem,
    configuratorReducer,
    createState,
    errorMessages,
    expectedFrom,
    groupBy,
    hasUnsavedItems,
    optionIndex,
    parseQuantity,
    saveBody,
    selectSingle,
    singleChoice,
    toCalcInput,
    toggleOption,
    toPayload,
    totalsFromConflict,
    totalsOf,
    unitNetCents,
} from './configurator';
import { calculateOffer } from './offer';

const detail = {
    version: { id: 7, price_cents: 2500000, model: 'Octavia', trim: 'Style' },
    standard: [],
    groups: [
        {
            id: 1, name: 'Boja', selection: 'single', default: { id: 10, name: 'Bela' },
            options: [{ id: 11, name: 'Metalik', price_cents: 120000 }, { id: 12, name: 'Crna', price_cents: 90000 }],
        },
        { id: 2, name: 'Paketi', selection: 'multiple', default: null, options: [{ id: 21, name: 'A', price_cents: 10000 }] },
    ],
    extras: [{ id: 31, name: 'Climatronic', price_cents: 150000 }],
};
const boja = detail.groups[0];

describe('optionIndex', () => {
    it('marks only single-group options as surcharges and keeps the group name', () => {
        const index = optionIndex(detail);

        expect(index.get(11)).toMatchObject({ is_surcharge: true, group_name: 'Boja', price_cents: 120000 });
        expect(index.get(21)).toMatchObject({ is_surcharge: false, group_name: 'Paketi' });
        expect(index.get(31)).toMatchObject({ is_surcharge: false, group_name: null });
        expect(index.has(10)).toBe(false); // the default is never an option
    });
});

describe('single group choice', () => {
    it('replaces the other option of the group and leaves the rest alone', () => {
        expect(selectSingle([31, 11], boja, 12)).toEqual([31, 12]);
        expect(selectSingle([11], boja, 11)).toEqual([11]);
    });

    it('null means the default: nothing of the group is chosen', () => {
        expect(selectSingle([31, 11], boja, null)).toEqual([31]);
        expect(singleChoice([31], boja)).toBeNull();
        expect(singleChoice([31, 12], boja)).toBe(12);
    });

    it('toggles independent extras', () => {
        expect(toggleOption([], 31)).toEqual([31]);
        expect(toggleOption([31, 21], 31)).toEqual([21]);
    });
});

describe('groupBy', () => {
    it('keeps the order of first appearance', () => {
        expect(groupBy([{ c: 'b', n: 1 }, { c: 'a', n: 2 }, { c: 'b', n: 3 }], (x) => x.c)).toEqual([
            ['b', [{ c: 'b', n: 1 }, { c: 'b', n: 3 }]],
            ['a', [{ c: 'a', n: 2 }]],
        ]);
        expect(groupBy([], (x) => x)).toEqual([]);
    });
});

describe('parseQuantity', () => {
    it('accepts whole numbers from 1 to the maximum', () => {
        expect(parseQuantity('3', 999)).toBe(3);
        expect(parseQuantity(999, 999)).toBe(999);
    });

    it.each(['0', '1000', '', '1.5', '-1', 'abc', '1e2', ' ', 2.5, NaN])('rejects %s', (value) => {
        expect(parseQuantity(value, 999)).toBeNull();
    });
});

describe('items and totals', () => {
    it('builds an item with the chosen options in the order of the detail, dropping unknown ids', () => {
        const item = buildItem(detail, [31, 11, 999], 2);

        expect(item.option_ids).toEqual([11, 31]);
        expect(item.options.map((o) => o.name)).toEqual(['Metalik', 'Climatronic']);
        expect(unitNetCents(item)).toBe(2500000 + 120000 + 150000);
    });

    it('adds the surcharge to the line instead of replacing the price', () => {
        const item = buildItem(detail, [11], 1);

        expect(totalsOf([item], 2000).totals.items[0].line_net_cents).toBe(2620000);
    });

    it('sends only ids and the quantity, never prices or names', () => {
        const item = buildItem(detail, [11, 31], 2, 5);

        expect(item.model_id).toBe(5);

        expect(toPayload([item])).toEqual([{ version_id: 7, quantity: 2, option_ids: item.option_ids }]);
        expect(JSON.stringify(toPayload([item]))).not.toMatch(/price|name|Metalik/);
    });

    it('computes the live totals with the same calculator the server mirrors', () => {
        const items = [buildItem(detail, [11, 31], 2), buildItem(detail, [], 1)];

        expect(totalsOf(items, 2000).totals).toEqual(calculateOffer(toCalcInput(items), 2000));
        // (2,500,000 + 120,000 + 150,000) * 2 + 2,500,000 = 8,040,000 net; VAT 20% = 1,608,000
        expect(totalsOf(items, 2000).totals).toMatchObject({ total_net_cents: 8040000, vat_cents: 1608000, total_gross_cents: 9648000 });
    });

    it('reports the exceeded limits as an error code instead of throwing', () => {
        const items = Array.from({ length: 21 }, () => buildItem(detail, [], 1));

        expect(totalsOf(items, 2000)).toEqual({ error: 'range' });
    });

    it('builds the save body with the totals the user saw and a trimmed note', () => {
        const items = [buildItem(detail, [31], 1)];
        const { totals } = totalsOf(items, 2000);

        expect(saveBody(items, '  Za firmu \n', totals)).toEqual({
            items: [{ version_id: 7, quantity: 1, option_ids: [31] }],
            note: 'Za firmu',
            expected_total_net_cents: 2650000,
            expected_total_gross_cents: 3180000,
        });
        expect(saveBody(items, '   ', totals).note).toBeNull();
        expect(expectedFrom(totals)).toEqual({ expected_total_net_cents: 2650000, expected_total_gross_cents: 3180000 });
    });

    it('takes the confirmed amounts from a 409 answer', () => {
        const data = { totals: { total_net_cents: 1, vat_cents: 2, total_gross_cents: 3 }, vat_rate_bp: 2500, message: 'x' };

        expect(totalsFromConflict(data)).toEqual({ total_net_cents: 1, vat_cents: 2, total_gross_cents: 3 });
    });
});

describe('configuratorReducer', () => {
    const item = buildItem(detail, [], 1);

    it('adds with a growing uid, replaces and removes by uid', () => {
        let state = configuratorReducer(createState(), { type: 'add', item });
        state = configuratorReducer(state, { type: 'add', item: buildItem(detail, [31], 2) });

        expect(state.items.map((i) => i.uid)).toEqual([1, 2]);

        state = configuratorReducer(state, { type: 'replace', uid: 1, item: buildItem(detail, [11], 3) });
        expect(state.items[0]).toMatchObject({ uid: 1, quantity: 3, option_ids: [11] });

        state = configuratorReducer(state, { type: 'remove', uid: 1 });
        expect(state.items.map((i) => i.uid)).toEqual([2]);

        // A removed uid is never reused.
        state = configuratorReducer(state, { type: 'add', item });
        expect(state.items.map((i) => i.uid)).toEqual([2, 3]);

        expect(configuratorReducer(state, { type: 'clear' }).items).toEqual([]);
    });

    it('rejects an unknown action and does not change the previous state', () => {
        const state = configuratorReducer(createState(), { type: 'add', item });

        expect(() => configuratorReducer(state, { type: 'nope' })).toThrow();
        expect(state.items).toHaveLength(1);
    });

    it('knows when there is something unsaved', () => {
        expect(hasUnsavedItems(createState().items)).toBe(false);
        expect(hasUnsavedItems(configuratorReducer(createState(), { type: 'add', item }).items)).toBe(true);
    });
});

describe('errorMessages', () => {
    it('flattens a 422 and falls back for anything else', () => {
        expect(errorMessages({ response: { status: 422, data: { errors: { a: ['one'], b: ['two', 'three'] } } } }, 'x')).toEqual(['one', 'two', 'three']);
        expect(errorMessages({ response: { status: 500, data: {} } }, 'fallback')).toEqual(['fallback']);
        expect(errorMessages({}, 'fallback')).toEqual(['fallback']);
    });
});
