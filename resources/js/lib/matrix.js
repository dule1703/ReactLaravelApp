// Helpers of the equipment matrix. Entries are the rows of trim_equipment of one model:
// { trim_id, item_id, group_id, item_name, availability: 'standard'|'optional', price_cents }.
// "Unavailable" is the absence of an entry. The server decides everything; this only shapes
// what the screen shows and what state the browser reports back (for conflict detection).

export const cellKey = (trimId, itemId) => `${trimId}:${itemId}`;

export function indexEntries(entries) {
    return new Map(entries.map((entry) => [cellKey(entry.trim_id, entry.item_id), entry]));
}

export const stateOf = (entry) => entry?.availability ?? 'none';

/** The state of a cell as the server compares it. */
export const expectedOf = (entry) => ({
    availability: stateOf(entry),
    price_cents: entry?.availability === 'optional' ? entry.price_cents : null,
});

export function groupEntriesOnTrim(entries, trimId, groupId) {
    if (groupId === null || groupId === undefined) {
        return [];
    }

    return entries.filter((entry) => entry.trim_id === trimId && entry.group_id === groupId);
}

/**
 * The entry that is standard in a single-choice group on the trim, other than the given item
 * (null when there is none): setting the item as standard then replaces it.
 */
export function otherStandard(item, groupEntries) {
    if (item.selection !== 'single') {
        return null;
    }

    return groupEntries.find((entry) => entry.availability === 'standard' && entry.item_id !== item.id) ?? null;
}

/** A price in cents as text for an input field: 41200 -> "412,00". */
export function centsToInput(cents) {
    return (cents / 100).toFixed(2).replace('.', ',');
}
