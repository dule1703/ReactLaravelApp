<?php

namespace App\Enums;

enum DriveType: string
{
    /** Front-wheel drive. */
    case Fwd = 'fwd';
    /** All-wheel drive (4x4). */
    case Awd = 'awd';
}
