<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Rejected input of OfferCalculator. `$category` is one of type | range | missing, the same
 * categories as OfferCalculationError in resources/js/lib/offer.js (messages are not compared).
 */
class OfferCalculationException extends InvalidArgumentException
{
    public const TYPE = 'type';

    public const RANGE = 'range';

    public const MISSING = 'missing';

    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }
}
