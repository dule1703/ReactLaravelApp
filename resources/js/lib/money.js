// Single place for currency settings. Amounts are integer cents, never floats.
export const CURRENCY = 'EUR';
export const LOCALE = 'sr-RS';

const formatter = new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: CURRENCY,
});

/**
 * Format an integer amount in cents for display, e.g. 1234567 -> "12.345,67 €".
 * The division happens only here, at the display edge; all calculations stay in cents.
 */
export function formatMoney(cents) {
    if (!Number.isInteger(cents)) {
        throw new TypeError('Money amount must be an integer number of cents.');
    }

    return formatter.format(cents / 100);
}
