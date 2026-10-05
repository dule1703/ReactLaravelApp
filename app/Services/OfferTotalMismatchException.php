<?php

namespace App\Services;

use RuntimeException;

/**
 * The totals the client saw differ from the ones the server calculated now (a price, a VAT rate
 * or the availability changed in between). Nothing was written and no number was used. The HTTP
 * layer (4.5b) answers 409 with these amounts so the client can confirm; the server always
 * writes only its own amounts.
 */
class OfferTotalMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $totalNetCents,
        public readonly int $vatCents,
        public readonly int $totalGrossCents,
        public readonly int $vatRateBp,
    ) {
        parent::__construct('The offer totals changed since the client calculated them.');
    }
}
