<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

enum ChargerCommandType: string
{
    case RemoteStart = 'remote_start';
    case RemoteStop = 'remote_stop';
}
