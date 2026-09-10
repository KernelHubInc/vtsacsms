<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

enum ChargingSessionOrigin: string
{
    case Remote = 'remote';
    case Charger = 'charger';
    case Reconstructed = 'reconstructed';
}
