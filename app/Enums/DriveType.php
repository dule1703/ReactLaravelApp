<?php

namespace App\Enums;

enum DriveType: string
{
    /** Front-wheel drive. */
    case Fwd = 'fwd';
    /** All-wheel drive (4x4). */
    case Awd = 'awd';
    /** Rear-wheel drive (e.g. Enyaq 60 and 85). */
    case Rwd = 'rwd';
}
