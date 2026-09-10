<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application\Gateway;

use App\Modules\Charging\Domain\Models\ChargerCommand;

interface GatewayCommandClient
{
    public function dispatch(ChargerCommand $command): GatewayCommandResult;
}
