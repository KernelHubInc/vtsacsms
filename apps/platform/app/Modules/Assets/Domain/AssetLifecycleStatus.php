<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

enum AssetLifecycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Retired = 'retired';
}
