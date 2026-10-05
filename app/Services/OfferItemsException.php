<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * The client's choice for the items of an offer is not acceptable. A ValidationException (so the
 * HTTP layer answers 422) with one key per item and field: `items.2.version_id`,
 * `items.2.option_ids.1`, or `items` for the offer as a whole. Messages are translated.
 */
class OfferItemsException extends ValidationException {}
