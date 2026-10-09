<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Assets\Application\GatewayStationQuery;
use App\Modules\Tenancy\Application\GatewayTenantQuery;

final readonly class RegisteredChargers
{
    public function __construct(private GatewayStationQuery $stations, private GatewayTenantQuery $tenants) {}

    /** @return array{tenant_id: string, charger_id: string, charge_point_identity: string}|null */
    public function resolve(string $identity, string $protocol): ?array
    {
        $station = $this->stations->forIdentity($identity);
        if ($station === null || ! $station['active'] || $station['protocol'] !== $protocol || ! $this->tenants->isActive($station['tenant_id'])) {
            return null;
        }

        // Registration supplies routing and tenancy, not proof of physical device identity.
        return array_intersect_key($station, array_flip(['tenant_id', 'charger_id', 'charge_point_identity']));
    }
}
