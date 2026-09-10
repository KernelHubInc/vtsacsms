<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Assets\Application\ConnectorSnapshot;
use App\Modules\Charging\Domain\AuthorizationStatus;
use App\Modules\Charging\Domain\ChargingSessionOrigin;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\SessionAnomaly;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Tariffs\Application\TariffSelector;
use App\Modules\Tariffs\Application\TariffSnapshotFactory;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class ChargingSessionCreator
{
    public function __construct(
        private TariffSelector $tariffs,
        private TariffSnapshotFactory $snapshots,
        private StateTransitionRecorder $transitions,
        private OutboxRecorder $outbox,
    ) {}

    public function create(
        ConnectorSnapshot $connector,
        ChargingSessionOrigin $origin,
        CarbonImmutable $requestedAt,
        ?string $requestedTariffVersionId = null,
        ?string $promotionCode = null,
        bool $requireTariff = false,
    ): ChargingSession {
        $version = $this->tariffs->select($connector, $requestedAt, $requestedTariffVersionId);
        if ($version === null && $requireTariff) {
            throw new DomainException('No published tariff applies to this connector and start time.');
        }

        $tariff = $version === null
            ? $this->snapshots->missing($connector, $requestedAt)
            : $this->snapshots->create($version, $connector, $requestedAt, $promotionCode);
        $anomalies = $version === null ? [SessionAnomaly::TariffMissing->value] : [];
        $session = ChargingSession::query()->create([
            'site_id' => $connector->siteId,
            'operator_id' => $connector->operatorId,
            'charging_station_id' => $connector->stationId,
            'evse_id' => $connector->evseId,
            'connector_id' => $connector->connectorId,
            'connector_maximum_power_w' => $connector->maximumPowerW,
            'charge_point_identity' => $connector->chargePointIdentity,
            'protocol' => $connector->protocol,
            'origin' => $origin,
            'state' => ChargingSessionState::Requested,
            'authorization_status' => AuthorizationStatus::Pending,
            'tariff_version_id' => $version?->getKey(),
            'tariff_snapshot' => $tariff['snapshot'],
            'tariff_snapshot_hash' => $tariff['hash'],
            'currency' => $version?->tariff->currency,
            'requested_at' => $requestedAt,
            'energy_wh' => 0,
            'duration_seconds' => 0,
            'parking_seconds' => 0,
            'idle_seconds' => 0,
            'anomaly_flags' => $anomalies,
            'aggregate_version' => 1,
        ]);
        $this->transitions->record('session', (string) $session->getKey(), null, ChargingSessionState::Requested->value, 'session_created');
        $this->outbox->record('charging.session.requested.v1', 'charging_session', (string) $session->getKey(), [
            'origin' => $origin->value,
            'connector_id' => $connector->connectorId,
            'tariff_version_id' => $version?->getKey(),
        ]);

        return $session;
    }
}
