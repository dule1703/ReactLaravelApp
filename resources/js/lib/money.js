// Single place for currency settings. Amounts are integer cents, never floats.
export const CURRENCY = 'EUR';
export const LOCALE = 'sr-RS';

const formatter = new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: CURRENCY,
});

/**
 * Parse a typed amount in euros into integer cents without floats; null if invalid.
 * Identical to App\Support\Money::parseEuros (shared cases in tests/fixtures/money-parse-cases.json);
 * the server is the authority, this only drives live previews.
 * "25.000,50" and "25.000" use dots for thousands; "25000.5" and "25.50" use a decimal dot.
 */
export function parseEuros(input, { signed = false } = {}) {
    let text = String(input).replace(/\s+/g, '');
    let negative = false;

    if (signed && (text[0] === '-' || text[0] === '+')) {
        negative = text[0] === '-';
        text = text.slice(1);
    }

    if (text === '' || !/^[0-9.,]+$/.test(text)) {
        return null;
    }

    let whole;
    let fraction;

    if (text.includes(',')) {
        const parts = text.split(',');
        if (parts.length !== 2) return null;
        [whole, fraction] = parts;
        if (fraction === '' || fraction.length > 2) return null;
    } else if (text.includes('.')) {
        const last = text.lastIndexOf('.');
        const after = text.slice(last + 1);

        if (after.length === 3) {
            whole = text;
            fraction = '';
        } else if (after.length === 1 || after.length === 2) {
            whole = text.slice(0, last);
            fraction = after;
            if (whole.includes('.')) return null;
        } else {
            return null;
        }
    } else {
        whole = text;
        fraction = '';
    }

    if (!/^([0-9]+|[0-9]{1,3}(\.[0-9]{3})+)$/.test(whole)) {
        return null;
    }

    whole = whole.replaceAll('.', '');
    if (whole.replace(/^0+/, '').length > 9) {
        return null;
    }

    const cents = parseInt(whole, 10) * 100 + parseInt(fraction.padEnd(2, '0') || '0', 10);

    return negative && cents !== 0 ? -cents : cents;
}

/**
 * Parse a typed percentage into basis points without floats; null if invalid.
 * "7,5" -> 750, "0,29" -> 29, "20" -> 2000. Identical to App\Support\Money::parsePercentBp.
 */
export function parsePercentBp(input, { signed = false } = {}) {
    let text = String(input).replace(/\s+/g, '');
    let negative = false;

    if (signed && (text[0] === '-' || text[0] === '+')) {
        negative = text[0] === '-';
        text = text.slice(1);
    }

    const match = /^([0-9]{1,3})(?:[.,]([0-9]{1,2}))?$/.exec(text);
    if (!match) {
        return null;
    }

    const bp = parseInt(match[1], 10) * 100 + parseInt((match[2] ?? '').padEnd(2, '0') || '0', 10);

    return negative && bp !== 0 ? -bp : bp;
}

/** Round non-negative cents to the nearest whole euro, half up. */
export function roundToEuro(cents) {
    return Math.floor((cents + 50) / 100) * 100;
}

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
