<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum ValuationMethod: string
{
    case MovingAverage = 'moving_average';
    case Standard = 'standard';
}
