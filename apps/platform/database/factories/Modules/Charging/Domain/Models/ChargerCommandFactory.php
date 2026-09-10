<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Charging\Domain\Models;

use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChargerCommand> */
final class ChargerCommandFactory extends Factory
{
    protected $model = ChargerCommand::class;

    public function definition(): array
    {
        $session = ChargingSession::factory()->create();

        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'session_id' => $session->getKey(),
            'charging_station_id' => $session->charging_station_id,
            'connector_id' => $session->connector_id,
            'charge_point_identity' => $session->charge_point_identity,
            'type' => ChargerCommandType::RemoteStart,
            'ocpp_action' => 'RemoteStartTransaction',
            'state' => ChargerCommandState::Requested,
            'idempotency_key' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'actor_id' => (string) Str::ulid(),
            'reason_code' => 'test.remote_start',
            'payload' => [],
            'expected_state' => [],
            'expires_at' => now('UTC')->addMinute(),
        ];
    }
}
