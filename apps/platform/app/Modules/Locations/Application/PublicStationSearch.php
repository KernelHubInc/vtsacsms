<?php

declare(strict_types=1);

namespace App\Modules\Locations\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PublicStationSearch
{
    /** @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters): array
    {
        $query = DB::table('charging_stations as station')
            ->join('sites as site', 'site.id', '=', 'station.site_id')
            ->join('organizations as operator', 'operator.id', '=', 'site.operator_organization_id')
            ->leftJoin('cities as city', 'city.id', '=', 'site.city_id')
            ->whereColumn('site.tenant_id', 'station.tenant_id')
            ->whereColumn('operator.tenant_id', 'site.tenant_id')
            ->where('site.lifecycle_status', 'active')
            ->where('site.is_public', true)
            ->whereNotNull('site.published_at')
            ->where('site.published_at', '<=', now('UTC'))
            ->where('station.lifecycle_status', 'active')
            ->where('station.is_public', true)
            ->whereNotNull('site.latitude')
            ->whereNotNull('site.longitude')
            ->select([
                'station.id', 'station.name', 'station.qr_identifier', 'station.site_id',
                'site.name as site_name', 'site.public_slug', 'site.address_line_1',
                'site.latitude', 'site.longitude', 'site.timezone', 'site.site_type',
                'city.name as city_name',
                'operator.id as operator_id', 'operator.name as operator_name',
            ]);

        if (isset($filters['operator_id'])) {
            $query->where('site.operator_organization_id', $filters['operator_id']);
        }
        if (isset($filters['site_type'])) {
            $query->where('site.site_type', $filters['site_type']);
        }
        if (isset($filters['query'])) {
            $query->where(function (Builder $nested) use ($filters): void {
                $nested->where('site.name', 'ilike', '%'.$filters['query'].'%')
                    ->orWhere('station.name', 'ilike', '%'.$filters['query'].'%');
            });
        }
        if (isset($filters['city'])) {
            $query->where('city.name', 'ilike', '%'.$filters['city'].'%');
        }

        $candidateLimit = min(max((int) $filters['limit'] * 5, 250), 1000);
        $stations = DB::getDriverName() === 'pgsql'
            ? array_values($this->postgis($query, $filters)->limit($candidateLimit)->get()->map(fn (object $row): array => $this->rowToArray($row))->all())
            : $this->portable($query->limit(2000)->get(), $filters);
        $stations = $this->withConnectorSignals($stations);
        $stations = $this->withOperatingHours($stations);
        $stations = $this->withAmenities($stations);

        return array_values(collect($stations)
            ->filter(fn (array $station): bool => $this->matchesFilters($station, $filters))
            ->take((int) $filters['limit'])
            ->all());
    }

    /** @param array<string, mixed> $filters */
    private function postgis(Builder $query, array $filters): Builder
    {
        if (isset($filters['latitude'], $filters['longitude'])) {
            $point = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';
            $query->selectRaw("ST_Distance(site.coordinates, {$point}) AS distance_m", [$filters['longitude'], $filters['latitude']])
                ->whereRaw("ST_DWithin(site.coordinates, {$point}, ?)", [$filters['longitude'], $filters['latitude'], $filters['radius_m'] ?? 10000])
                ->orderBy('distance_m');
        }
        if (isset($filters['west'], $filters['south'], $filters['east'], $filters['north'])) {
            $query->whereRaw('ST_Intersects(site.coordinates::geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))', [
                $filters['west'], $filters['south'], $filters['east'], $filters['north'],
            ]);
        }

        return $query->orderBy('station.id');
    }

    /** @param Collection<int, \stdClass> $rows
     * @param  array<string, mixed>  $filters
     * @return list<non-empty-array<string, mixed>>
     */
    private function portable(Collection $rows, array $filters): array
    {
        return array_values($rows->map(function (object $rawRow) use ($filters): array {
            $row = $this->rowToArray($rawRow);
            $row['distance_m'] = isset($filters['latitude'], $filters['longitude'])
                ? $this->distanceMeters((float) $filters['latitude'], (float) $filters['longitude'], (float) $row['latitude'], (float) $row['longitude'])
                : null;

            return $row;
        })->filter(function (array $row) use ($filters): bool {
            if (isset($filters['radius_m']) && (float) $row['distance_m'] > (int) $filters['radius_m']) {
                return false;
            }

            return ! isset($filters['west'], $filters['south'], $filters['east'], $filters['north'])
                || ((float) $row['longitude'] >= (float) $filters['west'] && (float) $row['longitude'] <= (float) $filters['east']
                    && (float) $row['latitude'] >= (float) $filters['south'] && (float) $row['latitude'] <= (float) $filters['north']);
        })->sortBy(fn (array $row): array => [$row['distance_m'] ?? PHP_FLOAT_MAX, $row['id']])->values()->all());
    }

    /** @param list<non-empty-array<string, mixed>> $stations
     * @return list<non-empty-array<string, mixed>>
     */
    private function withConnectorSignals(array $stations): array
    {
        if ($stations === []) {
            return $stations;
        }
        $stationCollection = collect($stations);
        $connectors = DB::table('connectors as connector')
            ->join('evses as evse', function ($join): void {
                $join->on('evse.id', '=', 'connector.evse_id')->on('evse.tenant_id', '=', 'connector.tenant_id');
            })
            ->join('connector_standards as standard', 'standard.id', '=', 'connector.connector_standard_id')
            ->join('charging_current_types as current', 'current.id', '=', 'connector.charging_current_type_id')
            ->leftJoin('charging_connector_statuses as signal', function ($join): void {
                $join->on('signal.connector_id', '=', 'connector.id')->on('signal.tenant_id', '=', 'connector.tenant_id');
            })
            ->whereIn('evse.charging_station_id', $stationCollection->pluck('id'))
            ->where('connector.lifecycle_status', 'active')
            ->select(['evse.charging_station_id', 'standard.code', 'standard.name', 'current.code as current_type', 'connector.maximum_power_w', 'signal.status', 'signal.observed_at', 'signal.stale_after_seconds'])
            ->get()->groupBy('charging_station_id');

        return array_values($stationCollection->map(function (array $station) use ($connectors): array {
            $items = $connectors->get($station['id'], collect());
            $fresh = $items->filter(function (object $item): bool {
                if ($item->observed_at === null) {
                    return false;
                }

                return CarbonImmutable::parse((string) $item->observed_at, 'UTC')->addSeconds((int) $item->stale_after_seconds)->isFuture();
            });
            $statuses = array_values($fresh->pluck('status')->filter()->all());
            $station['availability'] = $this->summarizeAvailability($statuses, $items->isNotEmpty() && $fresh->isEmpty());
            $station['is_stale'] = $station['availability'] === 'stale';
            $station['status_observed_at'] = $items->pluck('observed_at')->filter()->sortDesc()->first();
            $station['maximum_power_w'] = (int) ($items->max('maximum_power_w') ?? 0);
            $station['connectors'] = $items->map(fn (object $item): array => [
                'standard' => $item->code,
                'name' => $item->name,
                'current_type' => $item->current_type,
                'maximum_power_w' => (int) $item->maximum_power_w,
            ])->unique(fn (array $item): string => $item['standard'].'-'.$item['maximum_power_w'])->values()->all();

            return $station;
        })->values()->all());
    }

    /** @param list<non-empty-array<string, mixed>> $stations
     * @return list<non-empty-array<string, mixed>>
     */
    private function withOperatingHours(array $stations): array
    {
        if ($stations === []) {
            return $stations;
        }
        $stationCollection = collect($stations);
        $hours = DB::table('site_operating_hours')->whereIn('site_id', $stationCollection->pluck('site_id'))
            ->get(['site_id', 'day_of_week', 'opens_at', 'closes_at', 'is_closed'])->groupBy('site_id');

        return array_values($stationCollection->map(function (array $station) use ($hours): array {
            $now = CarbonImmutable::now((string) $station['timezone']);
            $today = $hours->get($station['site_id'], collect())->firstWhere('day_of_week', $now->dayOfWeek);
            $station['open_now'] = $today !== null && ! (bool) $today->is_closed && $today->opens_at !== null && $today->closes_at !== null
                && $now->format('H:i:s') >= $today->opens_at && $now->format('H:i:s') < $today->closes_at;

            return $station;
        })->values()->all());
    }

    /** @param list<mixed> $statuses */
    private function summarizeAvailability(array $statuses, bool $stale): string
    {
        if (in_array('available', $statuses, true)) {
            return 'available';
        }
        if (array_intersect(['occupied', 'reserved'], $statuses) !== []) {
            return 'busy';
        }
        if (in_array('faulted', $statuses, true)) {
            return 'faulted';
        }
        if (array_intersect(['offline', 'unavailable'], $statuses) !== []) {
            return 'offline';
        }

        return $stale ? 'stale' : 'unknown';
    }

    /** @param list<non-empty-array<string, mixed>> $stations
     * @return list<non-empty-array<string, mixed>>
     */
    private function withAmenities(array $stations): array
    {
        if ($stations === []) {
            return $stations;
        }

        $stationCollection = collect($stations);
        $amenities = DB::table('site_amenity as assigned')
            ->join('site_amenities as amenity', 'amenity.id', '=', 'assigned.site_amenity_id')
            ->whereIn('assigned.site_id', $stationCollection->pluck('site_id'))
            ->get(['assigned.site_id', 'amenity.code', 'amenity.name'])
            ->groupBy('site_id');

        return array_values($stationCollection->map(function (array $station) use ($amenities): array {
            $station['amenities'] = $amenities->get($station['site_id'], collect())
                ->map(fn (object $amenity): array => ['code' => $amenity->code, 'name' => $amenity->name])
                ->values()->all();

            return $station;
        })->values()->all());
    }

    /** @param array<string, mixed> $station
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(array $station, array $filters): bool
    {
        if (isset($filters['availability']) && $station['availability'] !== $filters['availability']) {
            return false;
        }
        if (isset($filters['min_power_w']) && $station['maximum_power_w'] < $filters['min_power_w']) {
            return false;
        }
        if (isset($filters['connector'])) {
            $connectors = is_array($station['connectors']) ? $station['connectors'] : [];
            if (! collect($connectors)->contains(fn (mixed $item): bool => is_array($item)
                && str_replace('_', '', strtolower((string) ($item['standard'] ?? ''))) === str_replace('_', '', strtolower((string) $filters['connector'])))) {
                return false;
            }
        }
        if (isset($filters['current'])) {
            $connectors = is_array($station['connectors']) ? $station['connectors'] : [];
            if (! collect($connectors)->contains(fn (mixed $item): bool => is_array($item)
                && strtoupper((string) ($item['current_type'] ?? '')) === strtoupper((string) $filters['current']))) {
                return false;
            }
        }
        if (isset($filters['amenity'])) {
            $amenities = is_array($station['amenities']) ? $station['amenities'] : [];
            if (! collect($amenities)->contains(fn (mixed $item): bool => is_array($item)
                && str_replace(['_', '-'], '', strtolower((string) ($item['code'] ?? ''))) === str_replace(['_', '-'], '', strtolower((string) $filters['amenity'])))) {
                return false;
            }
        }

        return ! isset($filters['open_now']) || $filters['open_now'] === false || $station['open_now'] === true;
    }

    /** @param array<string, mixed>|object $row
     * @return non-empty-array<string, mixed>
     */
    private function rowToArray(object|array $row): array
    {
        $data = is_array($row) ? $row : get_object_vars($row);

        return [
            'id' => $data['id'], 'name' => $data['name'], 'qr_identifier' => $data['qr_identifier'],
            'site_id' => $data['site_id'], 'site_name' => $data['site_name'], 'public_slug' => $data['public_slug'],
            'address_line_1' => $data['address_line_1'], 'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
            'timezone' => $data['timezone'], 'site_type' => $data['site_type'], 'operator_id' => $data['operator_id'],
            'city_name' => $data['city_name'] ?? null, 'operator_name' => $data['operator_name'], 'distance_m' => $data['distance_m'] ?? null,
        ];
    }

    private function distanceMeters(float $latitude, float $longitude, float $otherLatitude, float $otherLongitude): float
    {
        $lat1 = deg2rad($latitude);
        $lat2 = deg2rad($otherLatitude);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad($otherLongitude - $longitude);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return 6371008.8 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
