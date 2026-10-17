<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Charging\Application\StationConnectionQuery;
use App\Modules\Tariffs\Application\TariffSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final readonly class StationSetupQuery
{
    /** @return array<string, mixed> */
    public function for(User $actor, ChargingStation $station): array
    {
        Gate::forUser($actor)->authorize('view', $station);
        $station->load(['site.operatingHours', 'evses.connectors.standard', 'evses.connectors.currentType', 'ocppVersion']);
        $site = $station->site;
        $connections = app(StationConnectionQuery::class);
        $presence = $connections->forStations([(string) $station->getKey()])[(string) $station->getKey()] ?? null;
        $statuses = $connections->connectorStates([(string) $station->getKey()]);
        $connectors = Connector::query()->with(['evse', 'standard', 'currentType'])->whereIn('evse_id', $station->evses->modelKeys())->get();
        $outlets = $connectors->map(function (Connector $connector) use ($statuses): array {
            $snapshot = app(ConnectorSnapshotQuery::class)->byId((string) $connector->getKey());
            $tariff = $snapshot === null ? null : app(TariffSelector::class)->select($snapshot, CarbonImmutable::now('UTC'));

            return [
                'id' => $connector->getKey(), 'evse' => $connector->evse->evse_number,
                'number' => $connector->connector_number, 'standard' => $connector->standard->name ?? 'Not configured',
                'current' => $connector->currentType->name ?? 'Not configured', 'power_w' => $connector->maximum_power_w,
                'lifecycle' => $connector->lifecycle_status->value,
                'status' => $statuses[$connector->getKey()]['label'] ?? 'Unknown',
                'pricing' => $tariff === null ? 'Needs active equipment and published pricing' : $tariff->tariff->name,
                'priced' => $tariff !== null, 'qr' => $connector->qr_identifier,
            ];
        })->values()->all();

        return [
            'name' => $station->name, 'identity' => $station->charge_point_identity,
            'site_id' => $site->getKey(), 'site_name' => $site->name,
            'protocol' => $station->ocppVersion->code ?? 'Not selected',
            'connection' => $presence['status'] ?? 'unknown', 'last_seen' => $presence['last_seen_at'] ?? 'No device messages yet',
            'outlets' => $outlets,
            'checks' => [
                'Location is active and public' => $site->lifecycle_status->value === 'active' && $site->is_public,
                'Map coordinates and publication are set' => $site->latitude !== null && $site->longitude !== null && $site->published_at !== null && ! $site->published_at->isFuture(),
                'Station is active, public and has an OCPP version' => $station->lifecycle_status->value === 'active' && $station->is_public && $station->ocppVersion !== null,
                'Every EVSE has an active connector' => $station->evses->isNotEmpty() && $station->evses->every(fn ($evse): bool => $evse->lifecycle_status->value === 'active' && $evse->connectors->isNotEmpty() && $evse->connectors->every(fn ($connector): bool => $connector->lifecycle_status->value === 'active')),
                'All seven operating days are configured' => $site->operatingHours->count() === 7 && $site->operatingHours->every(fn ($hour): bool => $hour->is_closed || ($hour->opens_at !== null && $hour->closes_at !== null && $hour->opens_at !== $hour->closes_at)),
                'Every connector has effective published pricing' => $outlets !== [] && collect($outlets)->every(fn (array $outlet): bool => $outlet['priced']),
            ],
        ];
    }
}
