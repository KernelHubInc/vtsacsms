<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum TrackingType: string
{
    case None = 'none';
    case Lot = 'lot';
    case Serial = 'serial';
}
