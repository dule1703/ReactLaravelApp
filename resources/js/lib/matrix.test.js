import { describe, expect, it } from 'vitest';
import { centsToInput, cellKey, expectedOf, groupEntriesOnTrim, indexEntries, otherStandard, stateOf } from './matrix';

const entries = [
    { trim_id: 1, item_id: 10, group_id: 5, item_name: 'Felne 16', availability: 'standard', price_cents: null },
    { trim_id: 1, item_id: 11, group_id: 5, item_name: 'Felne 17', availability: 'optional', price_cents: 41200 },
    { trim_id: 2, item_id: 10, group_id: 5, item_name: 'Felne 16', availability: 'optional', price_cents: 0 },
    { trim_id: 1, item_id: 20, group_id: null, item_name: 'Klima', availability: 'standard', price_cents: null },
];

describe('matrix helpers', () => {
    it('indexes entries by trim and item', () => {
        expect(indexEntries(entries).get(cellKey(1, 11)).item_name).toBe('Felne 17');
        expect(indexEntries(entries).get(cellKey(2, 11))).toBeUndefined();
    });

    it('reports the state the server compares', () => {
        expect(stateOf(undefined)).toBe('none');
        expect(expectedOf(undefined)).toEqual({ availability: 'none', price_cents: null });
        expect(expectedOf(entries[0])).toEqual({ availability: 'standard', price_cents: null });
        expect(expectedOf(entries[1])).toEqual({ availability: 'optional', price_cents: 41200 });
        expect(expectedOf(entries[2])).toEqual({ availability: 'optional', price_cents: 0 });
    });

    it('finds the entries of a group on one trim', () => {
        expect(groupEntriesOnTrim(entries, 1, 5)).toHaveLength(2);
        expect(groupEntriesOnTrim(entries, 1, null)).toEqual([]);
    });

    it('finds the other standard item only in single-choice groups', () => {
        const group = groupEntriesOnTrim(entries, 1, 5);

        expect(otherStandard({ id: 11, selection: 'single' }, group).item_id).toBe(10);
        expect(otherStandard({ id: 10, selection: 'single' }, group)).toBeNull();
        expect(otherStandard({ id: 11, selection: 'multiple' }, group)).toBeNull();
        expect(otherStandard({ id: 20, selection: undefined }, [])).toBeNull();
    });

    it('formats cents for an input field', () => {
        expect(centsToInput(41200)).toBe('412,00');
        expect(centsToInput(0)).toBe('0,00');
        expect(centsToInput(5)).toBe('0,05');
    });
});
