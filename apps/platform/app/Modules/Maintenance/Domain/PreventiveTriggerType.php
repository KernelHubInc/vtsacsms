<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain;

enum PreventiveTriggerType: string
{
    case Date = 'date';
    case RuntimeSeconds = 'runtime_seconds';
    case SessionCount = 'session_count';
    case EnergyWh = 'energy_wh';
}
