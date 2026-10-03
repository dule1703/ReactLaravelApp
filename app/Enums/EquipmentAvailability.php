<?php

namespace App\Enums;

/**
 * How an equipment item is offered on a trim. "Not available" is the absence of a row.
 */
enum EquipmentAvailability: string
{
    /** Included in the trim price; carries no price of its own. */
    case Standard = 'standard';
    /** Extra, with its own net price (0 means a free option). */
    case Optional = 'optional';
}
