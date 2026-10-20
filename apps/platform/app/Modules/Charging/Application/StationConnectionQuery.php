<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StationConnectionQuery
{
    /**
     * Callers must supply IDs from their authorized station or connector listing.
     *
     * @param  list<string>  $stationIds
     * @param  list<string>  $connectorIds
     * @return array<string, array{station_id:string,label:string}>
     */
    public function connectorStates(array $stationIds = [], array $connectorIds = []): array
    {
        if ($stationIds === [] && $connectorIds === []) {
            return [];
        }

        $rows = DB::table('connectors as connector')
            ->join('evses as evse', fn ($join) => $join->on('evse.id', '=', 'connector.evse_id')->on('evse.tenant_id', '=', 'connector.tenant_id'))
            ->join('charging_stations as station', fn ($join) => $join->on('station.id', '=', 'evse.charging_station_id')->on('station.tenant_id', '=', 'evse.tenant_id'))
            ->leftJoin('charging_connector_statuses as signal', fn ($join) => $join->on('signal.connector_id', '=', 'connector.id')->on('signal.tenant_id', '=', 'connector.tenant_id'))
            ->when($stationIds !== [], fn ($query) => $query->whereIn('station.id', $stationIds))
            ->when($connectorIds !== [], fn ($query) => $query->whereIn('connector.id', $connectorIds))
            ->get(['connector.id', 'station.id as station_id', 'signal.status', 'signal.observed_at', 'signal.stale_after_seconds', 'signal.ocpp_connection_id', 'signal.reported_status']);
        $stationKeys = [];
        foreach ($rows as $row) {
            $stationKeys[] = (string) $row->station_id;
        }
        $connections = $this->forStations($stationKeys);
        $result = [];
        foreach ($rows as $row) {
            $availability = $this->availability($connections[$row->station_id] ?? null, $row->status ?? 'unknown', $row->observed_at ?? now()->toIso8601String(), (int) ($row->stale_after_seconds ?? 0), $row->ocpp_connection_id);
            $status = $this->reportedStatus($availability, $row->reported_status) ?? $availability;
            $result[$row->id] = ['station_id' => $row->station_id, 'label' => match ($status) {
                'suspendedev' => 'Paused by vehicle',
                'suspendedevse' => 'Paused by charger',
                'occupied' => 'In use',
                default => ucfirst($status),
            }];
        }

        return $result;
    }

    /**
     * Callers supply station IDs from an authorized or published station query.
     *
     * @param  list<string>  $stationIds
     * @return array<string, array{status:string,last_seen_at:string,connected_at:string,connection_id:string}>
     */
    public function forStations(array $stationIds): array
    {
        $result = [];
        $rows = DB::table('charging_station_connections as connection')
            ->join('charging_stations as station', function ($join): void {
                $join->on('station.id', '=', 'connection.charging_station_id')->on('station.tenant_id', '=', 'connection.tenant_id');
            })->whereIn('station.id', $stationIds)
            ->get(['station.id', 'connection.connected', 'connection.connected_at', 'connection.last_seen_at', 'connection.connection_id']);
        foreach ($rows as $row) {
            $result[(string) $row->id] = [
                'status' => $this->status((bool) $row->connected, (string) $row->last_seen_at),
                'last_seen_at' => CarbonImmutable::parse((string) $row->last_seen_at)->utc()->toIso8601String(),
                'connected_at' => CarbonImmutable::parse((string) $row->connected_at)->utc()->toIso8601String(),
                'connection_id' => (string) $row->connection_id,
            ];
        }

        return $result;
    }

    public function status(?bool $connected, ?string $lastSeen): string
    {
        if ($connected === null || $lastSeen === null) {
            return 'unknown';
        }

        return $connected && CarbonImmutable::parse($lastSeen)->addSeconds((int) config('charging.connection_stale_after_seconds'))->isFuture()
            ? 'online' : 'offline';
    }

    public function reportedStatus(string $availability, ?string $reported): ?string
    {
        $expected = match ($reported) {
            'preparing', 'charging', 'suspendedev', 'suspendedevse', 'finishing', 'occupied' => 'occupied',
            'available', 'reserved', 'unavailable', 'faulted' => $reported,
            default => null,
        };

        return $expected !== null && $availability === $expected ? $reported : null;
    }

    /** @param array{status:string,last_seen_at:string,connected_at:string,connection_id:string}|null $connection */
    public function availability(?array $connection, string $status, string $observedAt, int $staleAfter, ?string $connectionId = null): string
    {
        $observed = CarbonImmutable::parse($observedAt);
        if ($connection !== null) {
            if ($connection['status'] !== 'online') {
                return 'offline';
            }
            // A new socket must report connector state before old availability is reused.
            if ($connectionId !== $connection['connection_id']) {
                return 'unknown';
            }

            return $status;
        }

        return $observed->addSeconds($staleAfter)->isFuture() ? $status : 'unknown';
    }
}
