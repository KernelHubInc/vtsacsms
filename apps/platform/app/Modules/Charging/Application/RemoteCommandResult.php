<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;

final readonly class RemoteCommandResult
{
    public function __construct(public ChargingSession $session, public ChargerCommand $command) {}
}
