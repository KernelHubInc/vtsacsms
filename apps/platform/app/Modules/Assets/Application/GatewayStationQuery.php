<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Models\User;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Organizations\Domain\PermissionKey;
use DomainException;

final class GatewayStationQuery
{
    /** @return array{tenant_id: string, charger_id: string, charge_point_identity: string, protocol: string, active: bool}|null */
    public function forIdentity(string $identity): ?array
    {
        // Global identities are unique (ADR 0010); only this machine-auth query crosses tenant scopes.
        $station = ChargingStation::withoutGlobalScopes()->with('ocppVersion')
            ->where('charge_point_identity', $identity)->first();
        if ($station === null) {
            return null;
        }

        return [
            'tenant_id' => $station->tenant_id,
            'charger_id' => (string) $station->getKey(),
            'charge_point_identity' => $station->charge_point_identity,
            'protocol' => match ($station->ocppVersion?->code) {
                '1.6J' => 'ocpp1.6', '2.0.1' => 'ocpp2.0.1', default => '',
            },
            'active' => $station->lifecycle_status === AssetLifecycleStatus::Active,
        ];
    }

    /** @return array{tenant_id: string, charger_id: string} */
    public function manageable(User $user, string $stationId): array
    {
        $station = app(AccessibleStationsQuery::class)->for($user, PermissionKey::AssetManage)
            ->whereKey($stationId)->firstOrFail();

        return ['tenant_id' => $station->tenant_id, 'charger_id' => (string) $station->getKey()];
    }

    public function validateAndLock(string $stationId, string $identity, string $protocol): void
    {
        // Serialize projection creation even before a station has a connection row.
        $station = ChargingStation::query()->with('ocppVersion')->whereKey($stationId)
            ->where('lifecycle_status', AssetLifecycleStatus::Active->value)->lockForUpdate()->first();
        $expected = match ($station?->ocppVersion?->code) {
            '1.6J' => 'ocpp1.6',
            '2.0.1' => 'ocpp2.0.1',
            default => null,
        };
        if ($station === null || $expected !== $protocol || ! hash_equals($station->charge_point_identity, $identity)) {
            throw new DomainException('OCPP event does not match an active station in this tenant.');
        }
    }
}
