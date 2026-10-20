<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\InboundOcppEvent;
use App\Modules\Charging\Domain\Models\MeterReading;
use Carbon\CarbonImmutable;

final class EnergyFlowDuration
{
    /**
     * Conservative sampled duration: both ends must report positive aggregate import power.
     * Never extrapolate into a pause, the future or an unobserved telemetry gap.
     *
     * @return array{seconds: int, complete: bool}
     */
    public function measure(ChargingSession $session): array
    {
        if ($session->started_at === null) {
            return ['seconds' => 0, 'complete' => false];
        }
        $gap = (int) ($session->tariff_snapshot['energy_flow_max_gap_seconds'] ?? 60);
        $connector = app(ConnectorSnapshotQuery::class)->byId((string) $session->connector_id);
        $connectorNumber = $session->tariff_snapshot['subject']['connector_number'] ?? $connector?->connectorNumber;
        $statuses = InboundOcppEvent::query()->where('aggregate_id', $session->charging_station_id)
            ->where('event_type', 'gateway.ocpp.status_notification.received.v1')->where('outcome', 'processed')
            ->whereBetween('occurred_at', [$session->started_at, $session->stopped_at ?? now('UTC')])
            ->orderBy('occurred_at')->get()->filter(function (InboundOcppEvent $event) use ($connectorNumber): bool {
                $data = $event->payload['data'] ?? [];

                return is_array($data) && ($data['connector_id'] ?? null) === $connectorNumber;
            });
        $pauses = $statuses->filter(fn (InboundOcppEvent $event): bool => strtolower((string) ($event->payload['data']['status'] ?? '')) !== 'charging')
            ->map(fn (InboundOcppEvent $event): CarbonImmutable => $event->occurred_at);
        $readings = MeterReading::query()->where('session_id', $session->getKey())
            ->where('measurand', 'Power.Active.Import')->where('unit', 'W')
            ->whereNull('phase')->where('sampled_at', '>=', $session->started_at)
            ->where('sampled_at', '<=', $session->stopped_at ?? now('UTC'))
            ->orderBy('sampled_at')->get()->groupBy(fn (MeterReading $reading): string => $reading->sampled_at->toISOString());
        $seconds = 0;
        $complete = $readings->count() >= 2;
        $previousAt = $session->started_at;
        $previousPower = 0;
        foreach ($readings as $atReadings) {
            $reading = $atReadings->first();
            $lastStatus = $statuses->last(fn (InboundOcppEvent $event): bool => $event->occurred_at->lte($reading->sampled_at));
            $paused = $lastStatus !== null && strtolower((string) ($lastStatus->payload['data']['status'] ?? '')) !== 'charging';
            $previousStatus = $statuses->last(fn (InboundOcppEvent $event): bool => $event->occurred_at->lte($previousAt));
            $previousPaused = $previousStatus !== null && strtolower((string) ($previousStatus->payload['data']['status'] ?? '')) !== 'charging';
            $elapsed = (int) $previousAt->diffInSeconds($reading->sampled_at);
            // Conflicting samples at the same timestamp are not evidence of energy flow.
            $power = $atReadings->pluck('value')->unique()->count() === 1 ? (int) $reading->value : 0;
            if ($atReadings->pluck('value')->unique()->count() !== 1 || $elapsed > $gap) {
                $complete = false;
            } elseif (! $paused && ! $previousPaused && $elapsed > 0 && $previousPower > 0 && $power > 0
                && ! $pauses->contains(fn (CarbonImmutable $pause): bool => $pause->gte($previousAt) && $pause->lte($reading->sampled_at))) {
                $seconds += $elapsed;
            }
            $previousAt = $reading->sampled_at;
            $previousPower = $power;
        }
        if ($session->stopped_at !== null && $previousAt->diffInSeconds($session->stopped_at) > $gap) {
            $complete = false;
        }

        return ['seconds' => $seconds, 'complete' => $complete];
    }
}
