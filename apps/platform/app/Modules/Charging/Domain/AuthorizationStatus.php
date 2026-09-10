<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

enum AuthorizationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case ChargerLocal = 'charger_local';
    case Unknown = 'unknown';
}
