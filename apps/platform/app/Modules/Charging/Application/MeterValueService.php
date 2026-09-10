<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\MeterReading;
use App\Modules\Charging\Domain\SessionAnomaly;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Tariffs\Application\RatingInput;
use App\Modules\Tariffs\Application\TariffEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class MeterValueService
{
    public function __construct(
        private TariffEngine $tariffs,
        private OutboxRecorder $outbox,
    ) {}

    /** @param list<mixed> $samples */
    public function record(
        ChargingSession $session,
        string $eventId,
        array $samples,
        CarbonImmutable $receivedAt,
    ): void {
        DB::transaction(function () use ($session, $eventId, $samples, $receivedAt): void {
            $locked = ChargingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $latestAt = MeterReading::query()->where('session_id', $locked->getKey())->max('sampled_at');
            $latest = $latestAt === null ? null : CarbonImmutable::parse($latestAt)->utc();
            $anomalies = $this->anomalies($locked);

            foreach ($samples as $index => $sample) {
                if (! is_array($sample)) {
                    $anomalies[] = SessionAnomaly::InvalidMeterValue->value;

                    continue;
                }
                $reading = $this->normalize($sample, $receivedAt);
                if ($reading === null) {
                    $anomalies[] = SessionAnomaly::InvalidMeterValue->value;

                    continue;
                }
                $outOfOrder = $latest !== null && $reading['sampled_at']->lt($latest);
                if ($outOfOrder) {
                    $anomalies[] = SessionAnomaly::OutOfOrderMeter->value;
                }
                MeterReading::query()->firstOrCreate(
                    ['event_id' => $eventId, 'sample_index' => $index],
                    [
                        'session_id' => $locked->getKey(),
                        'sampled_at' => $reading['sampled_at'],
                        'received_at' => $receivedAt,
                        'measurand' => $reading['measurand'],
                        'unit' => $reading['unit'],
                        'value' => $reading['value'],
                        'context' => $reading['context'],
                        'phase' => $reading['phase'],
                        'is_out_of_order' => $outOfOrder,
                    ],
                );
                $latest = $latest === null || $reading['sampled_at']->gt($latest) ? $reading['sampled_at'] : $latest;
            }

            [$energyWh, $reset] = $this->recalculateEnergy($locked);
            if ($reset) {
                $anomalies[] = SessionAnomaly::MeterReset->value;
            }
            $locked->forceFill([
                'energy_wh' => $energyWh,
                'duration_seconds' => $locked->started_at === null ? 0 : max(0, $locked->started_at->diffInSeconds($receivedAt)),
                'anomaly_flags' => array_values(array_unique($anomalies)),
                'last_protocol_event_at' => $receivedAt,
            ]);
            if ($locked->started_at !== null) {
                $rating = $this->tariffs->rate($locked->tariff_snapshot, new RatingInput(
                    energyWh: $energyWh,
                    durationSeconds: (int) $locked->duration_seconds,
                    parkingSeconds: (int) $locked->parking_seconds,
                    idleSeconds: (int) $locked->idle_seconds,
                    startedAt: $locked->started_at,
                ));
                $locked->estimated_cost_minor = $rating->totalMinor;
            }
            $locked->save();
            $this->outbox->record('charging.session.energy_updated.v1', 'charging_session', (string) $locked->getKey(), [
                'event_id' => $eventId,
                'energy_wh' => $energyWh,
                'estimated_cost_minor' => $locked->estimated_cost_minor,
                'currency' => $locked->currency,
            ], $eventId);
        });
    }

    /** @param array<string, mixed> $sample
     * @return array{sampled_at: CarbonImmutable, measurand: string, unit: string, value: int, context: ?string, phase: ?string}|null
     */
    private function normalize(array $sample, CarbonImmutable $receivedAt): ?array
    {
        $unit = $sample['unit'] ?? null;
        $measurand = $sample['measurand'] ?? null;
        $value = $this->integer($sample['value'] ?? null);
        if (! is_string($unit) || ! in_array($unit, ['Wh', 'W'], true) || ! is_string($measurand) || $value === null || $value < 0) {
            return null;
        }

        try {
            $sampledAt = isset($sample['timestamp']) && is_string($sample['timestamp'])
                ? CarbonImmutable::parse($sample['timestamp'])->utc()
                : $receivedAt;
        } catch (Throwable) {
            return null;
        }

        return [
            'sampled_at' => $sampledAt,
            'measurand' => $measurand,
            'unit' => $unit,
            'value' => $value,
            'context' => is_string($sample['context'] ?? null) ? $sample['context'] : null,
            'phase' => is_string($sample['phase'] ?? null) ? $sample['phase'] : null,
        ];
    }

    /** @return array{int, bool} */
    private function recalculateEnergy(ChargingSession $session): array
    {
        $readings = MeterReading::query()
            ->where('session_id', $session->getKey())
            ->where('unit', 'Wh')
            ->where('measurand', 'Energy.Active.Import.Register')
            ->orderBy('sampled_at')
            ->orderBy('id')
            ->get();
        $previous = $session->meter_start_wh === null ? null : (int) $session->meter_start_wh;
        $energy = 0;
        $reset = false;

        foreach ($readings as $reading) {
            $current = (int) $reading->value;
            if ($previous === null) {
                $previous = $current;
                $session->meter_start_wh = $current;

                continue;
            }
            if ($current >= $previous) {
                $energy += $current - $previous;
            } else {
                $reset = true;
                $energy += $current;
                if (! $reading->is_meter_reset) {
                    $reading->forceFill(['is_meter_reset' => true])->save();
                }
            }
            $previous = $current;
        }

        if ($session->meter_stop_wh !== null && $previous !== null) {
            $stop = (int) $session->meter_stop_wh;
            if ($stop >= $previous) {
                $energy += $stop - $previous;
            } else {
                $reset = true;
                $energy += $stop;
            }
        }

        return [$energy, $reset];
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && $value <= PHP_INT_MAX) {
            return (int) $value;
        }

        return null;
    }

    /** @return list<string> */
    private function anomalies(ChargingSession $session): array
    {
        return array_values(array_filter($session->anomaly_flags, 'is_string'));
    }
}
