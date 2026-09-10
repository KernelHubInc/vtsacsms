<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Charging\Domain\AuthorizationStatus;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\ChargingSessionOrigin;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\ConnectorAvailability;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ConnectorReservation;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Jobs\DispatchChargerCommand;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RemoteStartService
{
    public function __construct(
        private CurrentTenant $tenant,
        private ConnectorSnapshotQuery $connectors,
        private ChargingSessionCreator $sessions,
        private AuthorizationTokenService $tokens,
        private ChargingSessionStateMachine $sessionStates,
        private ConnectorAvailabilityStateMachine $connectorStates,
        private StateTransitionRecorder $transitions,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
    ) {}

    public function request(
        string $connectorId,
        string $idempotencyKey,
        ?string $tariffVersionId = null,
        ?string $promotionCode = null,
    ): RemoteCommandResult {
        $existing = ChargerCommand::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->assertSameIntent($existing, $connectorId, $tariffVersionId, $promotionCode);

            return new RemoteCommandResult($existing->session, $existing);
        }

        $connector = $this->connectors->byId($connectorId) ?? throw new DomainException('Connector is not active or does not exist.');
        if (! in_array($connector->protocol, ['ocpp1.6', 'ocpp2.0.1'], true)) {
            throw new DomainException('The connector protocol is not supported for remote start.');
        }
        $context = $this->tenant->get();
        if ($context->actorId === null) {
            throw new DomainException('Remote start requires an authenticated human actor.');
        }
        $now = CarbonImmutable::now('UTC');
        $expiresAt = $now->addSeconds(max(30, (int) config('services.ocpp_gateway.start_expiry_seconds', 120)));

        $result = DB::transaction(function () use ($connector, $idempotencyKey, $tariffVersionId, $promotionCode, $context, $now, $expiresAt): RemoteCommandResult {
            $existing = ChargerCommand::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertSameIntent($existing, $connector->connectorId, $tariffVersionId, $promotionCode);

                return new RemoteCommandResult($existing->session, $existing);
            }

            $status = ConnectorStatus::query()->where('connector_id', $connector->connectorId)->lockForUpdate()->first();
            // A concurrent request can commit while this transaction waits for the connector lock.
            $existing = ChargerCommand::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertSameIntent($existing, $connector->connectorId, $tariffVersionId, $promotionCode);

                return new RemoteCommandResult($existing->session, $existing);
            }
            if ($status === null || $status->status !== ConnectorAvailability::Available || $status->observed_at->addSeconds($status->stale_after_seconds)->isPast()) {
                throw new DomainException('Connector is not currently available for remote start.');
            }

            $session = $this->sessions->create(
                $connector,
                ChargingSessionOrigin::Remote,
                $now,
                $tariffVersionId,
                $promotionCode,
                true,
            );
            $session->start_deadline_at = $expiresAt;
            $session->save();
            $generatedToken = $this->tokens->issue($expiresAt);
            $generatedToken->model->forceFill(['session_id' => $session->getKey()])->save();
            $session->forceFill([
                'authorization_token_id' => $generatedToken->model->getKey(),
                'authorization_status' => AuthorizationStatus::Approved,
            ])->save();
            ConnectorReservation::query()->create([
                'connector_id' => $connector->connectorId,
                'session_id' => $session->getKey(),
                'expires_at' => $expiresAt,
            ]);
            $this->connectorStates->transition($status, ConnectorAvailability::Reserved, 'remote_start_reserved', [
                'session_id' => (string) $session->getKey(),
                'observed_at' => $now,
            ]);
            $this->sessionStates->transition($session, ChargingSessionState::Authorizing, 'authorization_started');
            $this->sessionStates->transition($session, ChargingSessionState::Authorized, 'platform_token_issued');
            $this->sessionStates->transition($session, ChargingSessionState::Starting, 'remote_start_requested');

            [$action, $payload, $secret] = $this->startCommandPayload($connector->protocol, $connector->evseNumber, $connector->connectorNumber, $generatedToken->plainText);
            $command = ChargerCommand::query()->create([
                'session_id' => $session->getKey(),
                'charging_station_id' => $connector->stationId,
                'connector_id' => $connector->connectorId,
                'charge_point_identity' => $connector->chargePointIdentity,
                'type' => ChargerCommandType::RemoteStart,
                'ocpp_action' => $action,
                'state' => ChargerCommandState::Requested,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $context->correlationId,
                'actor_id' => $context->actorId,
                'reason_code' => 'driver.remote_start',
                'payload' => $payload,
                'secret_payload' => $secret,
                'expected_state' => [
                    'connector' => ConnectorAvailability::Reserved->value,
                    'session_state' => ChargingSessionState::Starting->value,
                    'requested_connector_id' => $connector->connectorId,
                    'requested_tariff_version_id' => $tariffVersionId,
                    'promotion_code_hash' => $this->promotionCodeHash($promotionCode),
                ],
                'expires_at' => $expiresAt,
            ]);
            $this->transitions->record('command', (string) $command->getKey(), null, ChargerCommandState::Requested->value, 'remote_start_created');
            $this->outbox->record('charging.command.requested.v1', 'charger_command', (string) $command->getKey(), [
                'session_id' => (string) $session->getKey(),
                'type' => ChargerCommandType::RemoteStart->value,
            ]);
            $this->audit->record(new AuditEntry(
                'charging.remote_start.requested',
                'charging_session',
                (string) $session->getKey(),
                AuditResult::Succeeded,
                metadata: ['command_id' => (string) $command->getKey(), 'connector_id' => $connector->connectorId],
            ));

            return new RemoteCommandResult($session, $command);
        });

        $this->dispatchAfterCommit($result->command);

        return $result;
    }

    /** @return array{string, array<string, mixed>, array<string, mixed>} */
    private function startCommandPayload(string $protocol, int $evseNumber, int $connectorNumber, string $token): array
    {
        if ($protocol === 'ocpp1.6') {
            return ['RemoteStartTransaction', ['connector_id' => $connectorNumber], ['id_tag' => $token]];
        }

        return [
            'RequestStartTransaction',
            ['remote_start_id' => random_int(1, 2_147_483_647), 'evse_id' => $evseNumber],
            ['id_token' => ['id_token' => $token, 'type' => 'Central']],
        ];
    }

    private function dispatchAfterCommit(ChargerCommand $command): void
    {
        $context = $this->tenant->get();
        $envelope = new TenantJobEnvelope(
            jobId: (string) Str::ulid(),
            schemaVersion: 1,
            tenantId: $context->tenantId,
            actorType: $context->actorType,
            actorId: $context->actorId,
            correlationId: $context->correlationId,
            causationId: (string) $command->getKey(),
        );
        DispatchChargerCommand::dispatch((string) $command->getKey(), $envelope)->afterCommit();
    }

    private function assertSameIntent(
        ChargerCommand $command,
        string $connectorId,
        ?string $tariffVersionId,
        ?string $promotionCode,
    ): void {
        if (
            $command->type !== ChargerCommandType::RemoteStart
            || ! hash_equals((string) $command->connector_id, $connectorId)
            || ($command->expected_state['requested_tariff_version_id'] ?? null) !== $tariffVersionId
            || ($command->expected_state['promotion_code_hash'] ?? null) !== $this->promotionCodeHash($promotionCode)
        ) {
            throw new DomainException('The idempotency key belongs to a different command intent.');
        }
    }

    private function promotionCodeHash(?string $promotionCode): ?string
    {
        return $promotionCode === null ? null : hash('sha256', mb_strtoupper($promotionCode));
    }
}
