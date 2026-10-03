<?php

namespace App\Support;

/**
 * Parsing of typed amounts without floats. Identical to resources/js/lib/money.js; both are
 * tested against tests/fixtures/money-parse-cases.json. The server is the authority; the
 * browser parser only drives live previews.
 *
 * Euros: a decimal comma is preferred and dots then group thousands ("25.000,50"). Without a
 * comma, a dot followed by 1-2 digits is decimal ("25000.5", "25.50") and dots followed by
 * groups of exactly 3 digits are thousands ("25.000", "1.250.000"). At most 2 decimals, at most
 * 9 integer digits, whitespace ignored. A sign is accepted only when $signed is true.
 *
 * Percent: digits with an optional decimal comma or dot, at most 2 decimals and 3 integer
 * digits; the result is in basis points ("7,5" -> 750, "0,29" -> 29, "20" -> 2000).
 */
class Money
{
    /** Largest accepted price: 10,000,000.00 EUR in cents (keeps all VAT math far below 2^53). */
    public const MAX_PRICE_CENTS = 1_000_000_000;

    /**
     * @return int|null cents, or null when the input is not a valid amount
     */
    public static function parseEuros(string $input, bool $signed = false): ?int
    {
        $text = preg_replace('/\s+/', '', $input);
        $negative = false;

        if ($signed && $text !== '' && ($text[0] === '-' || $text[0] === '+')) {
            $negative = $text[0] === '-';
            $text = substr($text, 1);
        }

        if ($text === '' || ! preg_match('/^[0-9.,]+$/', $text)) {
            return null;
        }

        if (str_contains($text, ',')) {
            if (substr_count($text, ',') !== 1) {
                return null;
            }
            [$whole, $fraction] = explode(',', $text);
            if ($fraction === '' || strlen($fraction) > 2) {
                return null;
            }
        } elseif (str_contains($text, '.')) {
            $last = strrpos($text, '.');
            $after = substr($text, $last + 1);

            if (strlen($after) === 3) {
                [$whole, $fraction] = [$text, '']; // thousands: "25.000"
            } elseif (strlen($after) === 1 || strlen($after) === 2) {
                [$whole, $fraction] = [substr($text, 0, $last), $after]; // decimal: "25.5"
                if (str_contains($whole, '.')) {
                    return null;
                }
            } else {
                return null;
            }
        } else {
            [$whole, $fraction] = [$text, ''];
        }

        if (! preg_match('/^([0-9]+|[0-9]{1,3}(\.[0-9]{3})+)$/', $whole)) {
            return null;
        }

        $whole = str_replace('.', '', $whole);
        if (strlen(ltrim($whole, '0')) > 9) {
            return null;
        }

        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    /**
     * @return int|null basis points, or null when the input is not a valid percentage
     */
    public static function parsePercentBp(string $input, bool $signed = false): ?int
    {
        $text = preg_replace('/\s+/', '', $input);
        $negative = false;

        if ($signed && $text !== '' && ($text[0] === '-' || $text[0] === '+')) {
            $negative = $text[0] === '-';
            $text = substr($text, 1);
        }

        if (! preg_match('/^([0-9]{1,3})(?:[.,]([0-9]{1,2}))?$/', $text, $match)) {
            return null;
        }

        $bp = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');

        return $negative ? -$bp : $bp;
    }

    /**
     * Basis points as a percentage string for forms: 2000 -> "20", 750 -> "7,5", 29 -> "0,29".
     */
    public static function formatPercentBp(int $bp): string
    {
        $text = intdiv($bp, 100);
        $fraction = rtrim(str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $text : $text.','.$fraction;
    }

    /**
     * Round non-negative cents to the nearest whole euro, half up.
     */
    public static function roundToEuro(int $cents): int
    {
        return intdiv($cents + 50, 100) * 100;
    }
}
