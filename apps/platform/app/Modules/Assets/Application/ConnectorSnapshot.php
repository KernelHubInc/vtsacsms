<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Modules\Charging\Domain\ConnectorAvailability;

final readonly class ConnectorSnapshot
{
    public function __construct(
        public string $tenantId,
        public string $siteId,
        public ?string $operatorId,
        public string $stationId,
        public string $evseId,
        public string $connectorId,
        public string $chargePointIdentity,
        public string $protocol,
        public int $evseNumber,
        public int $connectorNumber,
        public int $maximumPowerW,
        public string $timezone,
        public ConnectorAvailability $availability,
    ) {}
}
