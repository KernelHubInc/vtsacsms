<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingStation;
use DomainException;

final class GatewayStationQuery
{
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
