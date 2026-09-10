<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Jobs\DispatchChargerCommand;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RemoteStopService
{
    public function __construct(
        private CurrentTenant $tenant,
        private ChargingSessionStateMachine $sessionStates,
        private StateTransitionRecorder $transitions,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
    ) {}

    public function request(ChargingSession $session, string $idempotencyKey): RemoteCommandResult
    {
        $existing = ChargerCommand::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if ($existing->type !== ChargerCommandType::RemoteStop || ! hash_equals((string) $session->getKey(), (string) $existing->session_id)) {
                throw new DomainException('The idempotency key belongs to a different command intent.');
            }

            return new RemoteCommandResult($session, $existing);
        }

        $context = $this->tenant->get();
        if ($context->actorId === null) {
            throw new DomainException('Remote stop requires an authenticated human actor.');
        }
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds(max(10, (int) config('services.ocpp_gateway.timeout_seconds', 20) + 5));
        $result = DB::transaction(function () use ($session, $idempotencyKey, $context, $expiresAt): RemoteCommandResult {
            $locked = ChargingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            // Re-check after acquiring the aggregate lock so concurrent retries return the committed intent.
            $existing = ChargerCommand::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->type !== ChargerCommandType::RemoteStop || ! hash_equals((string) $locked->getKey(), (string) $existing->session_id)) {
                    throw new DomainException('The idempotency key belongs to a different command intent.');
                }

                return new RemoteCommandResult($locked, $existing);
            }
            $allowed = [
                ChargingSessionState::Starting,
                ChargingSessionState::Charging,
                ChargingSessionState::SuspendedByEv,
                ChargingSessionState::SuspendedByEvse,
            ];
            if (! in_array($locked->state, $allowed, true)) {
                throw new DomainException('The charging session is not in a remotely stoppable state.');
            }
            if ($locked->protocol_transaction_id === null) {
                throw new DomainException('The charger transaction identifier is not yet known.');
            }

            [$action, $payload] = $this->stopCommandPayload($locked->protocol, $locked->protocol_transaction_id);
            $previousState = $locked->state;
            $command = ChargerCommand::query()->create([
                'session_id' => $locked->getKey(),
                'charging_station_id' => $locked->charging_station_id,
                'connector_id' => $locked->connector_id,
                'charge_point_identity' => $locked->charge_point_identity,
                'type' => ChargerCommandType::RemoteStop,
                'ocpp_action' => $action,
                'state' => ChargerCommandState::Requested,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $context->correlationId,
                'actor_id' => $context->actorId,
                'reason_code' => 'driver.remote_stop',
                'payload' => $payload,
                'secret_payload' => null,
                'expected_state' => ['session_state' => $previousState->value],
                'expires_at' => $expiresAt,
            ]);
            $this->transitions->record('command', (string) $command->getKey(), null, ChargerCommandState::Requested->value, 'remote_stop_created');
            $this->outbox->record('charging.command.requested.v1', 'charger_command', (string) $command->getKey(), [
                'session_id' => (string) $locked->getKey(),
                'type' => ChargerCommandType::RemoteStop->value,
            ]);
            $this->sessionStates->transition($locked, ChargingSessionState::Stopping, 'remote_stop_requested');
            $this->audit->record(new AuditEntry(
                'charging.remote_stop.requested',
                'charging_session',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                metadata: ['command_id' => (string) $command->getKey()],
            ));

            return new RemoteCommandResult($locked, $command);
        });

        $this->dispatchAfterCommit($result->command);

        return $result;
    }

    /** @return array{string, array<string, int|string>} */
    private function stopCommandPayload(string $protocol, string $transactionId): array
    {
        if ($protocol === 'ocpp1.6') {
            if (! ctype_digit($transactionId)) {
                throw new DomainException('OCPP 1.6 transaction identifiers must be numeric.');
            }

            return ['RemoteStopTransaction', ['transaction_id' => (int) $transactionId]];
        }

        return ['RequestStopTransaction', ['transaction_id' => $transactionId]];
    }

    private function dispatchAfterCommit(ChargerCommand $command): void
    {
        $context = $this->tenant->get();
        DispatchChargerCommand::dispatch(
            (string) $command->getKey(),
            new TenantJobEnvelope(
                jobId: (string) Str::ulid(),
                schemaVersion: 1,
                tenantId: $context->tenantId,
                actorType: $context->actorType,
                actorId: $context->actorId,
                correlationId: $context->correlationId,
                causationId: (string) $command->getKey(),
            ),
        )->afterCommit();
    }
}
