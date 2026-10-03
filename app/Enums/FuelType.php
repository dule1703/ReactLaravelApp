<?php

namespace App\Enums;

enum FuelType: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case PluginHybrid = 'phev';
    case Electric = 'electric';
}
