<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

enum TaxTreatment: string
{
    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
}
