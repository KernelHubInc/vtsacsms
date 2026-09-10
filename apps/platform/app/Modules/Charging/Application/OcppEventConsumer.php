<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Assets\Application\ConnectorSnapshot;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Charging\Domain\AuthorizationStatus;
use App\Modules\Charging\Domain\ChargingSessionOrigin;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\ConnectorAvailability;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\InboundOcppEvent;
use App\Modules\Charging\Domain\SessionAnomaly;
use App\Modules\Maintenance\Application\Contracts\FaultObservationContract;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Ulid;
use Throwable;

final readonly class OcppEventConsumer
{
    public function __construct(
        private CurrentTenant $tenant,
        private ConnectorSnapshotQuery $connectors,
        private ChargingSessionCreator $sessions,
        private ChargingSessionStateMachine $states,
        private ConnectorAvailabilityStateMachine $connectorStates,
        private ConnectorReservationService $reservations,
        private MeterValueService $meters,
        private ChargeDetailRecordGenerator $cdrs,
        private FaultObservationContract $faults,
    ) {}

    /** @param array<string, mixed> $event */
    public function consume(array $event): OcppEventConsumptionResult
    {
        $this->validateEnvelope($event);
        $context = $this->tenant->get();
        if (! hash_equals($context->tenantId, (string) $event['tenant_id'])) {
            throw new DomainException('OCPP event tenant does not match the established tenant context.');
        }
        $existing = InboundOcppEvent::query()->whereKey($event['event_id'])->first();
        if ($existing !== null) {
            return new OcppEventConsumptionResult((string) $existing->outcome, true, $existing->error_code);
        }

        try {
            DB::transaction(function () use ($event): void {
                $inbound = InboundOcppEvent::query()->create([
                    'event_id' => $event['event_id'],
                    'tenant_id' => $event['tenant_id'],
                    'event_type' => $event['event_type'],
                    'schema_version' => $event['schema_version'],
                    'aggregate_id' => $event['aggregate_id'],
                    'correlation_id' => $event['correlation_id'],
                    'causation_id' => $event['causation_id'],
                    'payload' => $event,
                    'occurred_at' => $event['occurred_at'],
                    'received_at' => now('UTC'),
                    'outcome' => 'received',
                ]);
                $this->handle($event);
                $inbound->forceFill(['outcome' => 'processed', 'processed_at' => now('UTC')])->save();
            });

            return new OcppEventConsumptionResult('processed', false);
        } catch (Throwable $exception) {
            report($exception);
            DB::transaction(function () use ($event, $exception): void {
                InboundOcppEvent::query()->firstOrCreate(
                    ['event_id' => $event['event_id']],
                    [
                        'tenant_id' => $event['tenant_id'],
                        'event_type' => $event['event_type'],
                        'schema_version' => $event['schema_version'],
                        'aggregate_id' => $event['aggregate_id'],
                        'correlation_id' => $event['correlation_id'],
                        'causation_id' => $event['causation_id'],
                        'payload' => $event,
                        'occurred_at' => $event['occurred_at'],
                        'received_at' => now('UTC'),
                        'processed_at' => now('UTC'),
                        'outcome' => 'quarantined',
                        'error_code' => $this->errorCode($exception),
                    ],
                );
            });

            return new OcppEventConsumptionResult('quarantined', false, $this->errorCode($exception));
        }
    }

    /** @param array<string, mixed> $event */
    private function handle(array $event): void
    {
        $data = $event['data'];
        if (! is_array($data)) {
            throw new DomainException('OCPP event data must be an object.');
        }
        $action = str($event['event_type'])->between('gateway.ocpp.', '.received.v1')->toString();
        $connector = $this->connector($event, $data);
        if (! hash_equals($connector->protocol, (string) ($data['protocol'] ?? ''))) {
            throw new DomainException('OCPP event protocol does not match the enrolled charger profile.');
        }
        $occurredAt = CarbonImmutable::parse((string) $event['occurred_at'])->utc();
        $sourceAt = $this->sourceTime($data, $occurredAt);

        match ($action) {
            'start_transaction' => $this->start($connector, $event, $data, $sourceAt),
            'stop_transaction' => $this->stop($connector, $event, $data, $sourceAt),
            'transaction_event' => $this->transactionEvent($connector, $event, $data, $sourceAt),
            'meter_values' => $this->meterValues($connector, $event, $data, $occurredAt),
            'status_notification' => $this->status($connector, $event, $data, $sourceAt),
            default => null,
        };
    }

    /** @param array<string, mixed> $event
     * @param  array<string, mixed>  $data
     */
    private function start(ConnectorSnapshot $connector, array $event, array $data, CarbonImmutable $sourceAt): void
    {
        $transactionId = $this->transactionId($data);
        $session = $this->sessionForStart($connector, $transactionId, $sourceAt);
        $samples = is_array($data['samples'] ?? null) ? array_values($data['samples']) : [];
        $meterStart = $this->integerOrNull($data['meter_start_wh'] ?? null) ?? $this->registerWh($samples, true);
        $session->forceFill([
            'protocol_transaction_id' => $transactionId,
            'started_at' => $session->started_at ?? $sourceAt,
            'meter_start_wh' => $meterStart,
            'authorization_status' => $this->authorizationStatus($data),
            'last_protocol_event_at' => $sourceAt,
            'anomaly_flags' => $this->eventAnomalies($session, $data),
        ])->save();
        if ($session->state === ChargingSessionState::Requested) {
            $this->states->transition($session, ChargingSessionState::Starting, 'charger_start_observed', eventId: (string) $event['event_id']);
        }
        $this->states->transition($session, ChargingSessionState::Charging, 'charger_transaction_started', [
            'protocol_transaction_id' => $transactionId,
        ], (string) $event['event_id']);
        $this->reservations->release((string) $session->getKey(), 'transaction_started');
        $this->setConnectorStatus($connector, ConnectorAvailability::Occupied, 'transaction_started', $sourceAt, (string) $event['event_id']);
        if ($samples !== []) {
            $this->meters->record($session, (string) $event['event_id'], $samples, $sourceAt);
        }
    }

    /** @param array<string, mixed> $event
     * @param  array<string, mixed>  $data
     */
    private function stop(ConnectorSnapshot $connector, array $event, array $data, CarbonImmutable $sourceAt): void
    {
        $session = $this->findSession($connector, $this->transactionId($data));
        $samples = is_array($data['samples'] ?? null) ? array_values($data['samples']) : [];
        $meterStop = $this->integerOrNull($data['meter_stop_wh'] ?? null) ?? $this->registerWh($samples, false);
        $session->forceFill([
            'meter_stop_wh' => $meterStop,
            'stopped_at' => $sourceAt,
            'duration_seconds' => $session->started_at === null ? 0 : max(0, (int) $session->started_at->diffInSeconds($sourceAt)),
            'last_protocol_event_at' => $sourceAt,
            'anomaly_flags' => $this->finalAnomalies($session, $data, $meterStop),
        ])->save();
        $this->meters->record($session, (string) $event['event_id'], $samples, $sourceAt);
        $session->refresh();
        if ($session->state !== ChargingSessionState::Finalizing) {
            $this->states->transition($session, ChargingSessionState::Finalizing, 'charger_transaction_ended', [
                'stop_reason' => $data['reason'] ?? null,
            ], (string) $event['event_id']);
        }
        $this->reservations->release((string) $session->getKey(), 'transaction_ended');
        $this->cdrs->generate($session->refresh());
    }

    /** @param array<string, mixed> $event
     * @param  array<string, mixed>  $data
     */
    private function transactionEvent(ConnectorSnapshot $connector, array $event, array $data, CarbonImmutable $sourceAt): void
    {
        $eventType = mb_strtolower((string) ($data['event_type'] ?? ''));
        if ($eventType === 'started') {
            $this->start($connector, $event, $data, $sourceAt);
        } elseif ($eventType === 'ended') {
            $this->stop($connector, $event, $data, $sourceAt);
        } elseif ($eventType === 'updated') {
            $session = $this->findOrReconstruct($connector, $this->transactionId($data), $sourceAt);
            $target = match (mb_strtolower((string) ($data['charging_state'] ?? ''))) {
                'suspendedev' => ChargingSessionState::SuspendedByEv,
                'suspendedevse' => ChargingSessionState::SuspendedByEvse,
                default => ChargingSessionState::Charging,
            };
            if ($session->state === ChargingSessionState::Requested) {
                $this->states->transition($session, ChargingSessionState::Starting, 'transaction_reconstructed', eventId: (string) $event['event_id']);
            }
            if ($session->state !== $target && $session->state->canTransitionTo($target)) {
                $this->states->transition($session, $target, 'charger_transaction_updated', eventId: (string) $event['event_id']);
            }
            $session->forceFill([
                'protocol_transaction_id' => $this->transactionId($data),
                'last_protocol_event_at' => $sourceAt,
                'anomaly_flags' => $this->eventAnomalies($session, $data),
            ])->save();
            $samples = is_array($data['samples'] ?? null) ? array_values($data['samples']) : [];
            if ($samples !== []) {
                $this->meters->record($session, (string) $event['event_id'], $samples, $sourceAt);
            }
        } else {
            throw new DomainException('Unsupported OCPP 2.0.1 transaction event type.');
        }
    }

    /** @param array<string, mixed> $event
     * @param  array<string, mixed>  $data
     */
    private function meterValues(ConnectorSnapshot $connector, array $event, array $data, CarbonImmutable $receivedAt): void
    {
        $transactionId = isset($data['protocol_transaction_id']) ? (string) $data['protocol_transaction_id'] : null;
        $session = $transactionId === null
            ? $this->activeSession($connector)
            : $this->findSession($connector, $transactionId);
        $samples = is_array($data['samples'] ?? null) ? array_values($data['samples']) : [];
        $this->meters->record($session, (string) $event['event_id'], $samples, $receivedAt);
        if (($data['clock_skew_detected'] ?? false) === true) {
            $this->addAnomaly($session, SessionAnomaly::ClockSkew);
        }
    }

    /** @param array<string, mixed> $event
     * @param  array<string, mixed>  $data
     */
    private function status(ConnectorSnapshot $connector, array $event, array $data, CarbonImmutable $sourceAt): void
    {
        $status = match (mb_strtolower((string) ($data['status'] ?? ''))) {
            'available' => ConnectorAvailability::Available,
            'reserved' => ConnectorAvailability::Reserved,
            'preparing', 'charging', 'suspendedev', 'suspendedevse', 'finishing', 'occupied' => ConnectorAvailability::Occupied,
            'unavailable' => ConnectorAvailability::Unavailable,
            'faulted' => ConnectorAvailability::Faulted,
            'offline' => ConnectorAvailability::Offline,
            default => ConnectorAvailability::Unknown,
        };
        $this->setConnectorStatus($connector, $status, 'ocpp_status_notification', $sourceAt, (string) $event['event_id']);
        $faultCode = $data['error_code'] ?? $data['vendor_error_code'] ?? null;
        $this->faults->observe(
            (string) $event['event_id'],
            $connector->siteId,
            'connector',
            $connector->connectorId,
            $status->value,
            is_string($faultCode) ? $faultCode : null,
            $sourceAt,
            [
                'protocol' => $connector->protocol,
                'charge_point_identity' => $connector->chargePointIdentity,
                'evse_number' => $connector->evseNumber,
                'connector_number' => $connector->connectorNumber,
                'vendor_error_code' => is_string($data['vendor_error_code'] ?? null)
                    ? $data['vendor_error_code']
                    : null,
            ],
        );
    }

    private function sessionForStart(ConnectorSnapshot $connector, string $transactionId, CarbonImmutable $sourceAt): ChargingSession
    {
        $existing = ChargingSession::query()
            ->where('charging_station_id', $connector->stationId)
            ->where('protocol', $connector->protocol)
            ->where('protocol_transaction_id', $transactionId)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return $existing;
        }
        $reserved = ChargingSession::query()
            ->where('connector_id', $connector->connectorId)
            ->whereIn('state', [ChargingSessionState::Requested->value, ChargingSessionState::Authorizing->value, ChargingSessionState::Authorized->value, ChargingSessionState::Starting->value])
            ->whereHas('commands', fn ($query) => $query->where('type', 'remote_start'))
            ->latest('requested_at')
            ->lockForUpdate()
            ->first();

        return $reserved ?? $this->reconstruct($connector, $transactionId, $sourceAt);
    }

    private function findOrReconstruct(ConnectorSnapshot $connector, string $transactionId, CarbonImmutable $sourceAt): ChargingSession
    {
        return ChargingSession::query()
            ->where('charging_station_id', $connector->stationId)
            ->where('protocol', $connector->protocol)
            ->where('protocol_transaction_id', $transactionId)
            ->lockForUpdate()
            ->first() ?? $this->reconstruct($connector, $transactionId, $sourceAt);
    }

    private function reconstruct(ConnectorSnapshot $connector, string $transactionId, CarbonImmutable $sourceAt): ChargingSession
    {
        $session = $this->sessions->create($connector, ChargingSessionOrigin::Reconstructed, $sourceAt);
        $session->forceFill([
            'protocol_transaction_id' => $transactionId,
            'started_at' => $sourceAt,
            'authorization_status' => AuthorizationStatus::Unknown,
            'anomaly_flags' => array_values(array_unique([
                ...$session->anomaly_flags,
                SessionAnomaly::ReconstructedAfterReconnect->value,
            ])),
        ])->save();

        return $session;
    }

    private function findSession(ConnectorSnapshot $connector, string $transactionId): ChargingSession
    {
        return ChargingSession::query()
            ->where('charging_station_id', $connector->stationId)
            ->where('protocol', $connector->protocol)
            ->where('protocol_transaction_id', $transactionId)
            ->lockForUpdate()
            ->first() ?? throw new DomainException('No charging session matches the charger transaction.');
    }

    private function activeSession(ConnectorSnapshot $connector): ChargingSession
    {
        return ChargingSession::query()
            ->where('connector_id', $connector->connectorId)
            ->whereIn('state', [
                ChargingSessionState::Starting->value,
                ChargingSessionState::Charging->value,
                ChargingSessionState::SuspendedByEv->value,
                ChargingSessionState::SuspendedByEvse->value,
                ChargingSessionState::Stopping->value,
            ])->latest('requested_at')->lockForUpdate()->first()
            ?? throw new DomainException('No active charging session accepts this meter value.');
    }

    private function setConnectorStatus(
        ConnectorSnapshot $connector,
        ConnectorAvailability $next,
        string $reason,
        CarbonImmutable $observedAt,
        string $eventId,
    ): void {
        $status = ConnectorStatus::query()->firstOrCreate(
            ['connector_id' => $connector->connectorId],
            ['status' => ConnectorAvailability::Unknown, 'observed_at' => $observedAt, 'stale_after_seconds' => 300],
        );
        $this->connectorStates->transition($status, $next, $reason, ['observed_at' => $observedAt], $eventId);
    }

    /** @param array<string, mixed> $event
     * @param  array<string, mixed>  $data
     */
    private function connector(array $event, array $data): ConnectorSnapshot
    {
        $evse = is_array($data['evse'] ?? null) ? $data['evse'] : [];
        $evseNumber = $this->integerOrNull($evse['id'] ?? $data['evse_id'] ?? null);
        $connectorNumber = $this->integerOrNull($evse['connector_id'] ?? $data['connector_id'] ?? null);

        return $this->connectors->forGatewayEvent((string) $event['aggregate_id'], $evseNumber, $connectorNumber)
            ?? throw new DomainException('OCPP event cannot be mapped to exactly one active connector.');
    }

    /** @param array<string, mixed> $data */
    private function transactionId(array $data): string
    {
        $value = $data['protocol_transaction_id'] ?? null;
        if ((! is_string($value) && ! is_int($value)) || (string) $value === '') {
            throw new DomainException('OCPP transaction evidence requires a protocol transaction identifier.');
        }

        return (string) $value;
    }

    /** @param array<string, mixed> $data */
    private function sourceTime(array $data, CarbonImmutable $fallback): CarbonImmutable
    {
        return is_string($data['protocol_timestamp'] ?? null)
            ? CarbonImmutable::parse($data['protocol_timestamp'])->utc()
            : $fallback;
    }

    /** @param array<string, mixed> $data */
    private function authorizationStatus(array $data): AuthorizationStatus
    {
        return match (mb_strtolower((string) ($data['authorization_status'] ?? ''))) {
            'accepted' => AuthorizationStatus::Approved,
            'blocked', 'expired', 'invalid', 'concurrenttx' => AuthorizationStatus::Denied,
            default => AuthorizationStatus::Unknown,
        };
    }

    /** @param array<string, mixed> $data
     * @return list<string>
     */
    private function eventAnomalies(ChargingSession $session, array $data): array
    {
        $anomalies = array_values(array_filter($session->anomaly_flags, 'is_string'));
        if (($data['clock_skew_detected'] ?? false) === true) {
            $anomalies[] = SessionAnomaly::ClockSkew->value;
        }

        return array_values(array_unique($anomalies));
    }

    /** @param array<string, mixed> $data
     * @return list<string>
     */
    private function finalAnomalies(ChargingSession $session, array $data, ?int $meterStop): array
    {
        $anomalies = $this->eventAnomalies($session, $data);
        if ($session->meter_start_wh === null) {
            $anomalies[] = SessionAnomaly::MissingStartMeter->value;
        }
        if ($meterStop === null) {
            $anomalies[] = SessionAnomaly::MissingStopMeter->value;
        }

        return array_values(array_unique($anomalies));
    }

    private function addAnomaly(ChargingSession $session, SessionAnomaly $anomaly): void
    {
        $session->forceFill(['anomaly_flags' => array_values(array_unique([
            ...array_values(array_filter($session->anomaly_flags, 'is_string')),
            $anomaly->value,
        ]))])->save();
    }

    private function integerOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /** @param list<mixed> $samples */
    private function registerWh(array $samples, bool $first): ?int
    {
        $values = [];
        foreach ($samples as $sample) {
            if (
                is_array($sample)
                && ($sample['unit'] ?? null) === 'Wh'
                && ($sample['measurand'] ?? null) === 'Energy.Active.Import.Register'
                && ($value = $this->integerOrNull($sample['value'] ?? null)) !== null
            ) {
                $values[] = $value;
            }
        }

        return $values === [] ? null : ($first ? $values[0] : $values[array_key_last($values)]);
    }

    /** @param array<string, mixed> $event */
    private function validateEnvelope(array $event): void
    {
        foreach (['event_id', 'tenant_id', 'aggregate_id', 'correlation_id'] as $field) {
            if (! is_string($event[$field] ?? null) || ! Ulid::isValid($event[$field])) {
                throw new DomainException("OCPP event {$field} must be a ULID.");
            }
        }
        if (($event['schema_version'] ?? null) !== 1 || ! is_string($event['event_type'] ?? null) || ! str($event['event_type'])->is('gateway.ocpp.*.received.v1')) {
            throw new DomainException('Unsupported OCPP event contract.');
        }
        if (! is_string($event['occurred_at'] ?? null) || ! array_key_exists('causation_id', $event)) {
            throw new DomainException('OCPP event timing and causation fields are required.');
        }
    }

    private function errorCode(Throwable $exception): string
    {
        return $exception instanceof DomainException ? 'invalid_protocol_evidence' : 'consumer_failure';
    }
}
