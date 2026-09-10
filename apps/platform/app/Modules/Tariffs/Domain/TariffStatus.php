<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

enum TariffStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
