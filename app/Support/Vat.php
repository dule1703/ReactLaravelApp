<?php

namespace App\Support;

/**
 * VAT arithmetic on integer cents and a rate in basis points (2000 = 20%). No floats.
 * Identical to resources/js/lib/vat.js; both are tested against tests/fixtures/vat-cases.json.
 *
 * Every division rounds half up. The VAT amount is rounded once and gross = net + VAT, which
 * equals round(net * (1 + rate)); net is derived from gross with the inverse rule.
 */
class Vat
{
    public const RATE_MIN_BP = 0;

    public const RATE_MAX_BP = 10000;

    /**
     * net = intdiv(gross * 10000 + intdiv(10000 + rate, 2), 10000 + rate)
     */
    public static function netFromGross(int $grossCents, int $rateBp): int
    {
        $divisor = 10000 + $rateBp;

        return intdiv($grossCents * 10000 + intdiv($divisor, 2), $divisor);
    }

    public static function vatAmount(int $netCents, int $rateBp): int
    {
        return intdiv($netCents * $rateBp + 5000, 10000);
    }

    public static function grossFromNet(int $netCents, int $rateBp): int
    {
        return $netCents + self::vatAmount($netCents, $rateBp);
    }
}
