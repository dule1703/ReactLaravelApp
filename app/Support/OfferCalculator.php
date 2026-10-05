<?php

namespace App\Support;

/**
 * Price of an offer from already resolved input: integer cents, no floats, no database.
 * Identical to resources/js/lib/offer.js; both are tested against tests/fixtures/offer-calc-cases.json.
 *
 * Input (the same snake_case keys as the offer columns); the rate is the one snapshotted in the offer:
 *
 *     [['version_price_cents' => 2000000, 'quantity' => 2, 'options' => [['price_cents' => 150000]]]]
 *
 * line_net_cents = (version_price_cents + sum of options.price_cents) * quantity. A surcharge of a
 * `single` option group is just an option price: it is ADDED to the line, never replaces it; standard
 * equipment has no price and is not passed in. WHAT is offered and at which price is decided by the
 * offer service (4.5a) from the catalog, not here. Total net is the sum of the lines; VAT is
 * computed ONCE on the total (Vat::vatAmount) and gross = net + VAT, so the total can differ by a
 * cent from the sum of per-line gross prices (the total is authoritative).
 *
 * Anything but integers in range is an error (OfferCalculationException), never rounded or cast.
 * The limits keep every intermediate value far below PHP_INT_MAX and JS MAX_SAFE_INTEGER.
 */
class OfferCalculator
{
    public const MAX_PRICE_CENTS = 1_000_000_000;

    public const MAX_QUANTITY = 999;

    public const MAX_OPTIONS = 200;

    public const MAX_TOTAL_NET_CENTS = 100_000_000_000;

    /** Lines per offer; keeps one request (and the held offer counter row) small. */
    public const MAX_ITEMS = 20;

    /** All options of all lines of an offer: the counter row stays locked until the rows are written. */
    public const MAX_TOTAL_OPTIONS = 500;

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{items: list<array{line_net_cents: int}>, total_net_cents: int, vat_cents: int, total_gross_cents: int}
     */
    public static function calculate(array $items, mixed $vatRateBp): array
    {
        $rate = self::integer($vatRateBp, 'vat_rate_bp', Vat::RATE_MIN_BP, Vat::RATE_MAX_BP);

        if (! array_is_list($items)) {
            throw new OfferCalculationException(OfferCalculationException::TYPE, 'items must be a list.');
        }

        if (count($items) > self::MAX_ITEMS) {
            throw new OfferCalculationException(OfferCalculationException::RANGE, 'Too many items in one offer.');
        }

        $lines = [];
        $totalNet = 0;
        $totalOptions = 0;

        foreach ($items as $item) {
            $line = self::lineNet($item);
            $totalOptions += count($item['options']);

            if ($totalOptions > self::MAX_TOTAL_OPTIONS) {
                throw new OfferCalculationException(OfferCalculationException::RANGE, 'Too many options in one offer.');
            }

            $totalNet += $line;

            if ($totalNet > self::MAX_TOTAL_NET_CENTS) {
                throw new OfferCalculationException(OfferCalculationException::RANGE, 'The offer total is too large.');
            }

            $lines[] = ['line_net_cents' => $line];
        }

        $vat = Vat::vatAmount($totalNet, $rate);

        return [
            'items' => $lines,
            'total_net_cents' => $totalNet,
            'vat_cents' => $vat,
            'total_gross_cents' => $totalNet + $vat,
        ];
    }

    private static function lineNet(mixed $item): int
    {
        if (! is_array($item)) {
            throw new OfferCalculationException(OfferCalculationException::TYPE, 'An item must be an object.');
        }

        $price = self::integer(self::key($item, 'version_price_cents'), 'version_price_cents', 0, self::MAX_PRICE_CENTS);
        $quantity = self::integer(self::key($item, 'quantity'), 'quantity', 1, self::MAX_QUANTITY);
        $options = self::key($item, 'options');

        if (! is_array($options) || ! array_is_list($options)) {
            throw new OfferCalculationException(OfferCalculationException::TYPE, 'options must be a list.');
        }

        if (count($options) > self::MAX_OPTIONS) {
            throw new OfferCalculationException(OfferCalculationException::RANGE, 'Too many options on one item.');
        }

        foreach ($options as $option) {
            if (! is_array($option)) {
                throw new OfferCalculationException(OfferCalculationException::TYPE, 'An option must be an object.');
            }

            $price += self::integer(self::key($option, 'price_cents'), 'price_cents', 0, self::MAX_PRICE_CENTS);
        }

        return $price * $quantity;
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private static function key(array $source, string $name): mixed
    {
        if (! array_key_exists($name, $source)) {
            throw new OfferCalculationException(OfferCalculationException::MISSING, "$name is required.");
        }

        return $source[$name];
    }

    private static function integer(mixed $value, string $name, int $min, int $max): int
    {
        if (! is_int($value)) {
            throw new OfferCalculationException(OfferCalculationException::TYPE, "$name must be an integer.");
        }

        if ($value < $min || $value > $max) {
            throw new OfferCalculationException(OfferCalculationException::RANGE, "$name must be between $min and $max.");
        }

        return $value;
    }
}
