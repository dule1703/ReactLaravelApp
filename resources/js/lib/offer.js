// Price of an offer from already resolved input: integer cents, no floats, no database.
// Identical to app/Support/OfferCalculator.php; both are tested against tests/fixtures/offer-calc-cases.json.
//
// Input uses the same snake_case keys as the offer columns; the rate is the one snapshotted in the offer:
//   [{ version_price_cents: 2000000, quantity: 2, options: [{ price_cents: 150000 }] }]
//
// line_net_cents = (version_price_cents + sum of options.price_cents) * quantity. A surcharge of a
// `single` option group is just an option price: it is ADDED to the line; standard equipment has no
// price and is not passed in. VAT is computed ONCE on the total net (vat.js) and gross = net + VAT,
// so the total can differ by a cent from the sum of per-line gross prices (the total is authoritative).
// Anything but integers in range throws OfferCalculationError, never rounded. The limits keep every
// intermediate value far below Number.MAX_SAFE_INTEGER (and below PHP_INT_MAX on the server).

import { vatAmount } from './vat';

export const MAX_PRICE_CENTS = 1_000_000_000;
export const MAX_QUANTITY = 999;
export const MAX_OPTIONS = 200;
export const MAX_TOTAL_NET_CENTS = 100_000_000_000;
export const MAX_ITEMS = 20;
export const MAX_TOTAL_OPTIONS = 500;

const RATE_MIN_BP = 0;
const RATE_MAX_BP = 10000;

/** category is one of 'type' | 'range' | 'missing' (same as App\Support\OfferCalculationException). */
export class OfferCalculationError extends Error {
    constructor(category, message) {
        super(message);
        this.name = 'OfferCalculationError';
        this.category = category;
    }
}

function integer(value, name, min, max) {
    if (!Number.isSafeInteger(value)) {
        throw new OfferCalculationError('type', `${name} must be an integer.`);
    }

    if (value < min || value > max) {
        throw new OfferCalculationError('range', `${name} must be between ${min} and ${max}.`);
    }

    // + 0 turns -0 into 0.
    return value + 0;
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function key(source, name) {
    if (!Object.hasOwn(source, name)) {
        throw new OfferCalculationError('missing', `${name} is required.`);
    }

    return source[name];
}

function lineNet(item) {
    if (!isObject(item)) {
        throw new OfferCalculationError('type', 'An item must be an object.');
    }

    let price = integer(key(item, 'version_price_cents'), 'version_price_cents', 0, MAX_PRICE_CENTS);
    const quantity = integer(key(item, 'quantity'), 'quantity', 1, MAX_QUANTITY);
    const options = key(item, 'options');

    if (!Array.isArray(options)) {
        throw new OfferCalculationError('type', 'options must be a list.');
    }

    if (options.length > MAX_OPTIONS) {
        throw new OfferCalculationError('range', 'Too many options on one item.');
    }

    for (const option of options) {
        if (!isObject(option)) {
            throw new OfferCalculationError('type', 'An option must be an object.');
        }

        price += integer(key(option, 'price_cents'), 'price_cents', 0, MAX_PRICE_CENTS);
    }

    return price * quantity;
}

/**
 * @returns {{items: {line_net_cents: number}[], total_net_cents: number, vat_cents: number, total_gross_cents: number}}
 */
export function calculateOffer(items, vatRateBp) {
    const rate = integer(vatRateBp, 'vat_rate_bp', RATE_MIN_BP, RATE_MAX_BP);

    if (!Array.isArray(items)) {
        throw new OfferCalculationError('type', 'items must be a list.');
    }

    if (items.length > MAX_ITEMS) {
        throw new OfferCalculationError('range', 'Too many items in one offer.');
    }

    const lines = [];
    let totalNet = 0;
    let totalOptions = 0;

    for (const item of items) {
        const line = lineNet(item);
        totalOptions += item.options.length;

        if (totalOptions > MAX_TOTAL_OPTIONS) {
            throw new OfferCalculationError('range', 'Too many options in one offer.');
        }

        totalNet += line;

        if (totalNet > MAX_TOTAL_NET_CENTS) {
            throw new OfferCalculationError('range', 'The offer total is too large.');
        }

        lines.push({ line_net_cents: line });
    }

    const vat = vatAmount(totalNet, rate);

    return {
        items: lines,
        total_net_cents: totalNet,
        vat_cents: vat,
        total_gross_cents: totalNet + vat,
    };
}
