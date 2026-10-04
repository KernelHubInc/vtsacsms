<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StationConnectionQuery
{
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
