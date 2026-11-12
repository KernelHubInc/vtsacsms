<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\StationConnection;
use Carbon\CarbonImmutable;

final readonly class PostChargeIdle
{
    public function __construct(private ChargeDetailRecordGenerator $cdrs, private StationConnectionQuery $connections) {}

    public function begin(ChargingSession $session, ?string $connection, CarbonImmutable $at, ?string $reason): bool
    {
        if (! ($session->tariff_snapshot['idle_fee_consent'] ?? false) || $session->started_at === null
            || $session->stopped_at === null || ($session->energy_wh === 0 && $session->energy_flow_seconds === 0)
            || $connection === null || $at->isFuture()
            || in_array(strtolower($reason ?? ''), ['evdisconnected', 'evdeparted', 'unlockcommand'], true)
            || ! $this->connected($session, $connection)) {
            return false;
        }

        $status = ConnectorStatus::query()->where('connector_id', $session->connector_id)->first();
        $statusAt = $status?->last_ocpp_event_at;
        $currentStatus = $status?->ocpp_connection_id === $connection && $statusAt !== null && ! $statusAt->isFuture();
        if ($currentStatus && $status->reported_status === 'available' && $statusAt->gte($session->stopped_at)) {
            return false;
        }

        $session->forceFill([
            'idle_connection_id' => $connection,
            'idle_started_at' => $at,
            'idle_occupied_at' => $currentStatus && $statusAt->gte($at->subSeconds(5))
                && $this->occupied($session, (string) $status->reported_status) ? $at->max($statusAt) : null,
        ])->save();

        return true;
    }

    public function observe(string $connectorId, string $status, ?string $connection, CarbonImmutable $at): void
    {
        $session = $this->pending($connectorId);
        if ($session === null || $session->idle_started_at === null || $at->isFuture()) {
            return;
        }
        if ($at->lt($session->idle_started_at)) {
            if ($status === 'available' && $session->stopped_at !== null && $at->gte($session->stopped_at)) {
                $this->finish($session, $at);
            }

            return;
        }
        if ($connection !== $session->idle_connection_id || ! $this->connected($session, $connection) || $at->gte($this->reviewDeadline($session))) {
            $this->finish($session);

            return;
        }
        if ($this->occupied($session, $status)) {
            if ($session->idle_occupied_at === null) {
                $session->forceFill(['idle_occupied_at' => $at])->save();
            }

            return;
        }
        $this->finish($session, $status === 'available' ? $at : null);
    }

    public function superseded(string $connectorId): void
    {
        $session = $this->pending($connectorId);
        if ($session !== null) {
            $this->finish($session);
        }
    }

    public function expireUnverified(ChargingSession $session): void
    {
        if ($session->idle_started_at !== null && $session->idle_connection_id !== null
            && (! $this->connected($session, $session->idle_connection_id) || $this->reviewDeadline($session)->isPast())) {
            $this->finish($session);
        }
    }

    private function pending(string $connectorId): ?ChargingSession
    {
        return ChargingSession::query()->where('connector_id', $connectorId)
            ->where('state', ChargingSessionState::Finalizing)->whereNotNull('idle_connection_id')
            ->lockForUpdate()->first();
    }

    private function finish(ChargingSession $session, ?CarbonImmutable $releasedAt = null): void
    {
        $session->forceFill(['idle_connection_id' => null, 'idle_ended_at' => $releasedAt]);
        $session->forceFill(['idle_seconds' => $session->confirmedPostChargeSeconds()])->save();
        $this->cdrs->generate($session);
    }

    private function occupied(ChargingSession $session, string $status): bool
    {
        return $status === 'finishing' || ($session->protocol === 'ocpp2.0.1' && $status === 'occupied');
    }

    private function connected(ChargingSession $session, ?string $connection): bool
    {
        $current = StationConnection::query()->where('charging_station_id', $session->charging_station_id)->first();

        return $current !== null && $current->connection_id === $connection
            && $this->connections->status($current->connected, $current->last_seen_at->toISOString()) === 'online';
    }

    private function reviewDeadline(ChargingSession $session): CarbonImmutable
    {
        return ($session->idle_started_at ?? $session->requested_at)
            ->addSeconds((int) $session->tariff_snapshot['idle_grace_seconds'])->addHours(2);
    }
}
