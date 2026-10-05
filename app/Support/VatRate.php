<?php

namespace App\Support;

use App\Models\Setting;
use UnexpectedValueException;

/**
 * The VAT rate an offer snapshots when it is created. Strict on purpose: a damaged setting
 * stops the offer instead of silently becoming 0% or 20%. The admin screens use the lenient
 * Setting::vatRateBp() (it only shows the value); a missing row is the one allowed fallback.
 */
class VatRate
{
    /**
     * @throws UnexpectedValueException when the stored value is not an integer in 0..10000
     */
    public static function current(): int
    {
        $value = Setting::where('key', Setting::VAT_RATE_BP)->value('value');

        if ($value === null) {
            return Setting::DEFAULT_VAT_RATE_BP;
        }

        if (! is_string($value) || ! preg_match('/^\d{1,5}$/', $value)) {
            throw new UnexpectedValueException('The stored VAT rate is not a whole number of basis points.');
        }

        $rate = (int) $value;

        if ($rate < Vat::RATE_MIN_BP || $rate > Vat::RATE_MAX_BP) {
            throw new UnexpectedValueException('The stored VAT rate is outside 0..10000 basis points.');
        }

        return $rate;
    }
}
