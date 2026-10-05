// Pure logic of the offer configurator (no React, no fetch): the state of the items of an offer,
// the choice inside option groups, the data sent to the server and the totals shown live.
// The server remains the only authority: it re-reads every price from the catalog and the totals
// computed here are only what the client SEES (sent back as expected_* to detect a change).

import { calculateOffer, OfferCalculationError } from './offer';

/**
 * Everything that can be chosen on a version, by option id.
 * @param {{groups: object[], extras: object[]}} detail  response of the version endpoint
 * @returns {Map<number, {id: number, name: string, price_cents: number, group_id: number|null, group_name: string|null, is_surcharge: boolean}>}
 */
export function optionIndex(detail) {
    const index = new Map();

    for (const group of detail.groups ?? []) {
        for (const option of group.options) {
            index.set(option.id, {
                id: option.id,
                name: option.name,
                price_cents: option.price_cents,
                group_id: group.id,
                group_name: group.name,
                // The price of an item of a single-choice group is a surcharge over the default.
                is_surcharge: group.selection === 'single',
            });
        }
    }

    for (const extra of detail.extras ?? []) {
        index.set(extra.id, {
            id: extra.id,
            name: extra.name,
            price_cents: extra.price_cents,
            group_id: null,
            group_name: null,
            is_surcharge: false,
        });
    }

    return index;
}

/**
 * Choose an option of a single-choice group: it replaces any other chosen option of that group;
 * `null` means "the default" (nothing is sent for it).
 * @param {number[]} chosen  chosen option ids
 * @param {{options: {id: number}[]}} group
 * @param {number|null} optionId
 * @returns {number[]}
 */
export function selectSingle(chosen, group, optionId) {
    const ids = new Set(group.options.map((option) => option.id));
    const next = chosen.filter((id) => !ids.has(id));

    return optionId === null ? next : [...next, optionId];
}

/** Toggle an independent extra or an option of a multiple group. */
export function toggleOption(chosen, optionId) {
    return chosen.includes(optionId) ? chosen.filter((id) => id !== optionId) : [...chosen, optionId];
}

/** The chosen option of a single-choice group, or null when the default is kept. */
export function singleChoice(chosen, group) {
    return group.options.find((option) => chosen.includes(option.id))?.id ?? null;
}

/** Group a list by a key, keeping the order of first appearance (no Object.groupBy: older browsers). */
export function groupBy(list, keyOf) {
    const groups = new Map();

    for (const item of list) {
        const key = keyOf(item);
        groups.set(key, [...(groups.get(key) ?? []), item]);
    }

    return [...groups.entries()];
}

/** Whole number from 1 to max (a typed value is a string until it is parsed). */
export function parseQuantity(value, max) {
    if (typeof value === 'number' ? !Number.isInteger(value) : !/^\d{1,4}$/.test(String(value).trim())) {
        return null;
    }

    const quantity = Number(value);

    return quantity >= 1 && quantity <= max ? quantity : null;
}

/**
 * One item of the offer as the page keeps it: the ids that will be sent plus the prices and names
 * that are only for display and the live totals.
 */
export function buildItem(detail, chosen, quantity, modelId = null) {
    const index = optionIndex(detail);
    // In the order the catalog shows them, only what still exists on this version.
    const ordered = [...index.keys()].filter((id) => chosen.includes(id));

    return {
        // model_id is only for editing the item again; it is never sent.
        model_id: modelId,
        version_id: detail.version.id,
        quantity,
        option_ids: ordered,
        version: detail.version,
        options: ordered.map((id) => index.get(id)),
    };
}

/** Net price of one vehicle: version + every chosen option (a surcharge is added, never replaces). */
export function unitNetCents(item) {
    return item.version.price_cents + item.options.reduce((sum, option) => sum + option.price_cents, 0);
}

const initial = { items: [], nextUid: 1 };

export function createState() {
    return { ...initial };
}

/** actions: add {item}, replace {uid, item}, remove {uid}, clear */
export function configuratorReducer(state, action) {
    switch (action.type) {
        case 'add':
            return { items: [...state.items, { ...action.item, uid: state.nextUid }], nextUid: state.nextUid + 1 };
        case 'replace':
            return { ...state, items: state.items.map((item) => (item.uid === action.uid ? { ...action.item, uid: action.uid } : item)) };
        case 'remove':
            return { ...state, items: state.items.filter((item) => item.uid !== action.uid) };
        case 'clear':
            return { ...state, items: [] };
        default:
            throw new Error(`Unknown action ${action.type}`);
    }
}

/** What is sent to the server: ids and quantity only, never a price or a name. */
export function toPayload(items) {
    return items.map((item) => ({
        version_id: item.version_id,
        quantity: item.quantity,
        option_ids: [...item.option_ids],
    }));
}

/** Same shape the calculator takes (the keys of the offer columns). */
export function toCalcInput(items) {
    return items.map((item) => ({
        version_price_cents: item.version.price_cents,
        quantity: item.quantity,
        options: item.options.map((option) => ({ price_cents: option.price_cents })),
    }));
}

/**
 * Live totals, or an error code when the limits are exceeded.
 * @returns {{totals: object}|{error: string}}
 */
export function totalsOf(items, vatRateBp) {
    try {
        return { totals: calculateOffer(toCalcInput(items), vatRateBp) };
    } catch (error) {
        if (error instanceof OfferCalculationError) {
            return { error: error.category };
        }

        throw error;
    }
}

/** The totals the user saw, in the keys the request needs. */
export function expectedFrom(totals) {
    return {
        expected_total_net_cents: totals.total_net_cents,
        expected_total_gross_cents: totals.total_gross_cents,
    };
}

/** The body of the save request. */
export function saveBody(items, note, totals) {
    const trimmed = note.trim();

    return { items: toPayload(items), note: trimmed === '' ? null : trimmed, ...expectedFrom(totals) };
}

/** The new server totals of a 409 become what the user confirms. */
export function totalsFromConflict(data) {
    return {
        total_net_cents: data.totals.total_net_cents,
        vat_cents: data.totals.vat_cents,
        total_gross_cents: data.totals.total_gross_cents,
    };
}

/** Per-field messages of a 422 in one flat list. */
export function errorMessages(error, fallback) {
    const data = error.response?.data;

    if (error.response?.status === 422 && data?.errors) {
        return Object.values(data.errors).flat();
    }

    return [data?.message ?? fallback];
}

export const hasUnsavedItems = (items) => items.length > 0;
