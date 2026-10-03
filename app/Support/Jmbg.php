<?php

namespace App\Support;

use RuntimeException;

/**
 * JMBG helpers: normalization, the lookup hash and the check digit.
 */
class Jmbg
{
    private const MIN_KEY_LENGTH = 32;

    /**
     * Trim and keep digits only, so "01 01-990 710 006" and "0101990710006" are the same JMBG.
     */
    public static function normalize(string $value): string
    {
        return preg_replace('/\D+/', '', trim($value));
    }

    /**
     * Keyed HMAC-SHA256 of the normalized JMBG, used for exact lookup and uniqueness.
     * Null for a blank value. Fails fast when JMBG_HASH_KEY is missing or too short,
     * so a hash made with a weak key never reaches the database.
     */
    public static function hash(?string $value): ?string
    {
        $normalized = self::normalize((string) $value);

        if ($normalized === '') {
            return null;
        }

        $key = (string) config('app.jmbg_hash_key');

        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new RuntimeException('JMBG_HASH_KEY must be set to at least '.self::MIN_KEY_LENGTH.' characters.');
        }

        return hash_hmac('sha256', $normalized, $key);
    }

    /**
     * Check digit for the first 12 digits (weights 7..2 twice, mod 11; 10 and 11 become 0).
     */
    public static function checkDigit(string $first12): int
    {
        $weights = [7, 6, 5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += $weight * (int) $first12[$i];
        }

        $digit = 11 - ($sum % 11);

        return $digit > 9 ? 0 : $digit;
    }

    public static function isValid(string $value): bool
    {
        $jmbg = self::normalize($value);

        return strlen($jmbg) === 13 && (int) $jmbg[12] === self::checkDigit(substr($jmbg, 0, 12));
    }
}
