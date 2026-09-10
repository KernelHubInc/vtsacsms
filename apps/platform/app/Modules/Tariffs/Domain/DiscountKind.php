<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

enum DiscountKind: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
