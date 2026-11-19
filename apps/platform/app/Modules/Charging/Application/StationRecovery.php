<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Assets\Application\GatewayStationQuery;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ConnectorReservation;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Jobs\DispatchChargerCommand;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class StationRecovery
{
    public function __construct(private CurrentTenant $tenant, private GatewayStationQuery $stations, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function request(User $actor, string $stationId, ChargerCommandType $type, array $data, string $idempotencyKey): ChargerCommand
    {
        abort_unless($type->isStationControl(), 422);
        $this->authorize($actor, $stationId, $type);
        $data = Validator::make($data, [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'idle_confirmed' => ['required', 'accepted'],
            'connector_id' => [$type === ChargerCommandType::UnlockConnector ? 'required' : 'nullable', 'ulid'],
        ])->validate();
        Validator::make(compact('idempotencyKey'), ['idempotencyKey' => ['required', 'string', 'max:160']])->validate();
        $context = $this->tenant->get();
        $command = DB::transaction(function () use ($actor, $stationId, $type, $data, $idempotencyKey, $context): ChargerCommand {
            $target = $this->authorize($actor, $stationId, $type);
            $this->stations->validateAndLock($stationId, $target['identity'], $target['protocol']);
            $connectorId = $type === ChargerCommandType::UnlockConnector ? $data['connector_id'] : null;
            $existing = ChargerCommand::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                abort_unless($existing->actor_id === $actor->public_id && $existing->charging_station_id === $stationId
                    && $existing->type === $type && $existing->connector_id === $connectorId, 409);

                return $existing;
            }
            $connection = $this->assertIdle($stationId);
            $this->assertNoRecovery($stationId);
            $payload = $target['protocol'] === 'ocpp1.6' ? ['type' => 'Soft'] : ['type' => 'OnIdle'];
            if ($type === ChargerCommandType::UnlockConnector) {
                $connector = app(ConnectorSnapshotQuery::class)->byId((string) $connectorId);
                abort_unless($connector !== null && $connector->stationId === $stationId, 422);
                $payload = ['connector_id' => $connector->connectorNumber];
                if ($target['protocol'] === 'ocpp2.0.1') {
                    $payload['evse_id'] = $connector->evseNumber;
                }
            }
            $command = ChargerCommand::query()->create([
                'charging_station_id' => $stationId, 'connector_id' => $connectorId, 'session_id' => null,
                'charge_point_identity' => $target['identity'], 'type' => $type,
                'ocpp_action' => $type === ChargerCommandType::UnlockConnector ? 'UnlockConnector' : 'Reset',
                'state' => ChargerCommandState::Requested, 'idempotency_key' => $idempotencyKey,
                'actor_id' => $actor->public_id, 'correlation_id' => $context->correlationId,
                'reason_code' => 'operator.'.$type->value, 'payload' => $payload,
                'expected_state' => ['connection_id' => $connection['connection_id'], 'protocol' => $target['protocol']],
                'expires_at' => now('UTC')->addSeconds(max(10, (int) config('services.ocpp_gateway.timeout_seconds', 20) + 5)),
            ]);
            app(StateTransitionRecorder::class)->record('command', (string) $command->getKey(), null, 'requested', 'station_recovery_requested');
            $this->audit->record(new AuditEntry('charging.'.$type->value.'.requested', 'charging_station', $stationId,
                AuditResult::Succeeded, reason: $data['reason'], metadata: ['command_id' => $command->getKey(), 'connector_id' => $connectorId]));
            DispatchChargerCommand::dispatch((string) $command->getKey(), new TenantJobEnvelope(
                jobId: (string) Str::ulid(), schemaVersion: 1, tenantId: $context->tenantId,
                actorType: $context->actorType, actorId: $context->actorId,
                correlationId: $context->correlationId, causationId: (string) $command->getKey(),
            ))->onQueue('charging')->afterCommit();

            return $command;
        });

        return $command;
    }

    /** @return array{station_id:string,site_id:string,identity:string,protocol:string} */
    private function authorize(User $actor, string $stationId, ChargerCommandType $type): array
    {
        $context = $this->tenant->get();
        abort_unless($actor->isEnabled() && $context->actorType === ActorType::Human && $context->actorId === $actor->public_id, 403);
        abort_unless(config('features.ocpp') && config('features.remote_charging'), 403);
        $this->stations->controlTarget($actor, $stationId, PermissionKey::AssetView);

        return $this->stations->controlTarget($actor, $stationId, $type->permission());
    }

    public function validateDispatch(ChargerCommand $command): void
    {
        $actor = User::query()->where('public_id', $command->actor_id)->firstOrFail();
        $target = $this->authorize($actor, $command->charging_station_id, $command->type);
        $this->stations->validateAndLock($command->charging_station_id, $target['identity'], $target['protocol']);
        $connection = $this->assertIdle($command->charging_station_id);
        if ($command->session_id !== null || $command->charge_point_identity !== $target['identity']
            || $connection['connection_id'] !== ($command->expected_state['connection_id'] ?? null)) {
            throw ValidationException::withMessages(['command' => 'The charger connection changed. Refresh its status before requesting recovery again.']);
        }
        $expected = $target['protocol'] === 'ocpp1.6' ? ['type' => 'Soft'] : ['type' => 'OnIdle'];
        $action = 'Reset';
        if ($command->type === ChargerCommandType::UnlockConnector) {
            $connector = app(ConnectorSnapshotQuery::class)->byId((string) $command->connector_id);
            abort_unless($connector !== null && $connector->stationId === $command->charging_station_id, 422);
            $expected = ['connector_id' => $connector->connectorNumber];
            if ($target['protocol'] === 'ocpp2.0.1') {
                $expected['evse_id'] = $connector->evseNumber;
            }
            $action = 'UnlockConnector';
        }
        abort_unless($command->ocpp_action === $action && $command->payload === $expected && $command->secret_payload === null, 422);
    }

    /** @return array{status:string,last_seen_at:string,connected_at:string,connection_id:string} */
    private function assertIdle(string $stationId): array
    {
        $connection = app(StationConnectionQuery::class)->forStations([$stationId])[$stationId] ?? null;
        $connectors = app(ConnectorSnapshotQuery::class)->forStation($stationId);
        $busy = ChargingSession::query()->where('charging_station_id', $stationId)
            ->whereNotIn('state', ['completed', 'failed', 'cancelled', 'expired'])->exists();
        $reserved = ConnectorReservation::query()->whereIn('connector_id', array_map(fn ($item): string => $item->connectorId, $connectors))
            ->whereNull('released_at')->where('expires_at', '>', now('UTC'))->exists();
        if ($connection === null || $connection['status'] !== 'online' || $connectors === [] || $busy || $reserved) {
            throw ValidationException::withMessages(['command' => 'Recovery requires an online charger with no pending, active or unsettled session. Stop and finalize the session first.']);
        }
        $statuses = ConnectorStatus::query()->whereIn('connector_id', array_map(fn ($item): string => $item->connectorId, $connectors))->get()->keyBy('connector_id');
        foreach ($connectors as $connector) {
            $status = $statuses->get($connector->connectorId);
            if ($status === null || $status->ocpp_connection_id !== $connection['connection_id']
                || $status->observed_at->addSeconds($status->stale_after_seconds)->isPast()
                || ! in_array($status->reported_status, ['available', 'finishing', 'unavailable', 'faulted'], true)) {
                throw ValidationException::withMessages(['command' => 'Wait for a fresh idle status from every connector. Recovery is blocked while a vehicle is preparing or charging.']);
            }
        }

        return $connection;
    }

    public function assertNoRecovery(string $stationId): void
    {
        $commands = ChargerCommand::query()->where('charging_station_id', $stationId)
            ->whereIn('type', ['unlock_connector', 'restart_charger'])
            ->whereIn('state', ['requested', 'dispatched', 'acknowledged', 'timed_out', 'delivery_unknown'])
            ->latest('id')->limit(10)->get();
        $connection = app(StationConnectionQuery::class)->forStations([$stationId])[$stationId] ?? null;
        $connectors = app(ConnectorSnapshotQuery::class)->forStation($stationId);
        $statuses = ConnectorStatus::query()->whereIn('connector_id', array_map(fn ($item): string => $item->connectorId, $connectors))->get()->keyBy('connector_id');
        foreach ($commands as $command) {
            if (! $command->state->isTerminal() || $command->dispatched_at === null || $connection === null || $connection['status'] !== 'online') {
                throw ValidationException::withMessages(['command' => 'A recovery command needs fresh charger evidence before another operation can start.']);
            }
            $after = $command->dispatched_at;
            if ($command->type === ChargerCommandType::RestartCharger && CarbonImmutable::parse($connection['connected_at'])->lte($after)) {
                throw ValidationException::withMessages(['command' => 'Wait for the charger to reconnect after its restart.']);
            }
            foreach ($connectors as $connector) {
                $status = $statuses->get($connector->connectorId);
                if ($status === null || $status->ocpp_connection_id !== $connection['connection_id'] || $status->observed_at->lte($after)
                    || $status->observed_at->addSeconds($status->stale_after_seconds)->isPast()
                    || ! in_array($status->reported_status, ['available', 'finishing', 'unavailable', 'faulted'], true)) {
                    throw ValidationException::withMessages(['command' => 'Wait for fresh idle connector reports after the recovery command.']);
                }
            }
        }
    }
}
