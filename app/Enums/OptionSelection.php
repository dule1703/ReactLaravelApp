<?php

namespace App\Enums;

/**
 * How the items of an option group are chosen: exactly one per trim (colors, wheels, upholstery)
 * or any number of independent ones.
 */
enum OptionSelection: string
{
    /** One of several: each trim has exactly one standard item, the others are surcharges. */
    case Single = 'single';
    case Multiple = 'multiple';
}
