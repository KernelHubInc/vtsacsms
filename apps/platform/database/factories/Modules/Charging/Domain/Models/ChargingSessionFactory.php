<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Charging\Domain\Models;

use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Charging\Domain\AuthorizationStatus;
use App\Modules\Charging\Domain\ChargingSessionOrigin;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChargingSession> */
final class ChargingSessionFactory extends Factory
{
    protected $model = ChargingSession::class;

    public function definition(): array
    {
        $connector = Connector::factory()->create();
        $connector->load('evse.station.site');
        $station = $connector->evse->station;
        $site = $station->site;
        $snapshot = [
            'pricing_status' => 'missing',
            'currency' => null,
            'selected_at' => now('UTC')->toISOString(),
            'timezone' => $site->timezone,
            'components' => [],
            'discounts' => [],
        ];

        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'site_id' => $site->getKey(),
            'operator_id' => $site->operator_organization_id,
            'charging_station_id' => $station->getKey(),
            'evse_id' => $connector->evse_id,
            'connector_id' => $connector->getKey(),
            'connector_maximum_power_w' => $connector->maximum_power_w,
            'charge_point_identity' => $station->charge_point_identity,
            'protocol' => 'ocpp1.6',
            'origin' => ChargingSessionOrigin::Charger,
            'state' => ChargingSessionState::Requested,
            'authorization_status' => AuthorizationStatus::Unknown,
            'tariff_snapshot' => $snapshot,
            'tariff_snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'requested_at' => now('UTC'),
            'anomaly_flags' => [],
        ];
    }
}
