// VAT arithmetic on integer cents and a rate in basis points (2000 = 20%). No floats.
// Identical to app/Support/Vat.php; both are tested against tests/fixtures/vat-cases.json.
// BigInt keeps the integer division exact. Every division rounds half up.

const BASIS = 10000n;

function assertInt(value, name) {
    if (!Number.isInteger(value)) {
        throw new TypeError(`${name} must be an integer.`);
    }
}

/** net = intdiv(gross * 10000 + intdiv(10000 + rate, 2), 10000 + rate) */
export function netFromGross(grossCents, rateBp) {
    assertInt(grossCents, 'grossCents');
    assertInt(rateBp, 'rateBp');

    const divisor = BASIS + BigInt(rateBp);

    return Number((BigInt(grossCents) * BASIS + divisor / 2n) / divisor);
}

export function vatAmount(netCents, rateBp) {
    assertInt(netCents, 'netCents');
    assertInt(rateBp, 'rateBp');

    return Number((BigInt(netCents) * BigInt(rateBp) + 5000n) / BASIS);
}

export function grossFromNet(netCents, rateBp) {
    return netCents + vatAmount(netCents, rateBp);
}
