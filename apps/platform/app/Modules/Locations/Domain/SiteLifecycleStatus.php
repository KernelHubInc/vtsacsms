<?php

declare(strict_types=1);

namespace App\Modules\Locations\Domain;

enum SiteLifecycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Retired = 'retired';
}
