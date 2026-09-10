<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Charging\Domain\ConnectorAvailability;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use Illuminate\Database\Eloquent\Builder;

final class ConnectorSnapshotQuery
{
    public function byId(string $connectorId): ?ConnectorSnapshot
    {
        return $this->snapshot($this->baseQuery()->whereKey($connectorId)->first());
    }

    public function forGatewayEvent(
        string $stationId,
        ?int $evseNumber,
        ?int $connectorNumber,
    ): ?ConnectorSnapshot {
        $query = $this->baseQuery()->whereHas(
            'evse',
            fn (Builder $query): Builder => $query->where('charging_station_id', $stationId),
        );

        if ($evseNumber !== null) {
            $query->whereHas('evse', fn (Builder $query): Builder => $query->where('evse_number', $evseNumber));
        }
        if ($connectorNumber !== null) {
            $query->where('connector_number', $connectorNumber);
        }

        $connectors = $query->limit(2)->get();

        return $connectors->count() === 1 ? $this->snapshot($connectors->first()) : null;
    }

    /** @return Builder<Connector> */
    private function baseQuery(): Builder
    {
        return Connector::query()
            ->with(['evse.station.site', 'evse.station.ocppVersion'])
            ->where('lifecycle_status', AssetLifecycleStatus::Active->value)
            ->whereHas('evse', fn (Builder $query): Builder => $query->where('lifecycle_status', AssetLifecycleStatus::Active->value))
            ->whereHas('evse.station', fn (Builder $query): Builder => $query->where('lifecycle_status', AssetLifecycleStatus::Active->value));
    }

    private function snapshot(?Connector $connector): ?ConnectorSnapshot
    {
        if ($connector === null) {
            return null;
        }

        $evse = $connector->evse;
        $station = $evse->station;
        $site = $station->site;
        $status = ConnectorStatus::query()->where('connector_id', $connector->getKey())->first();
        $availability = $status->status ?? ConnectorAvailability::Unknown;

        if ($status !== null && $status->observed_at->addSeconds($status->stale_after_seconds)->isPast()) {
            $availability = ConnectorAvailability::Unknown;
        }

        return new ConnectorSnapshot(
            tenantId: (string) $connector->tenant_id,
            siteId: (string) $site->getKey(),
            operatorId: $site->operator_organization_id === null ? null : (string) $site->operator_organization_id,
            stationId: (string) $station->getKey(),
            evseId: (string) $evse->getKey(),
            connectorId: (string) $connector->getKey(),
            chargePointIdentity: (string) $station->charge_point_identity,
            protocol: match ($station->ocppVersion?->code) {
                '1.6J' => 'ocpp1.6',
                '2.0.1' => 'ocpp2.0.1',
                default => 'unsupported',
            },
            evseNumber: (int) $evse->evse_number,
            connectorNumber: (int) $connector->connector_number,
            maximumPowerW: (int) $connector->maximum_power_w,
            timezone: (string) $site->timezone,
            availability: $availability,
        );
    }
}
