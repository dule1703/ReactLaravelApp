<?php

namespace App\Enums;

/**
 * The order of the cases is the display order of equipment categories.
 */
enum EquipmentCategory: string
{
    case Safety = 'safety';
    case Comfort = 'comfort';
    case Exterior = 'exterior';
    case Interior = 'interior';
    case Multimedia = 'multimedia';
    /** Driving and chassis, e.g. sport suspension, driving modes. */
    case Driving = 'driving';
}
