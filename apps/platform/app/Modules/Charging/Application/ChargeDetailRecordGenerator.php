<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\ChargeDetailRecordState;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargeDetailRecord;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\SessionAnomaly;
use App\Modules\Tariffs\Application\RatingInput;
use App\Modules\Tariffs\Application\TariffEngine;
use Illuminate\Support\Facades\DB;
use JsonException;

final readonly class ChargeDetailRecordGenerator
{
    public function __construct(
        private TariffEngine $tariffs,
        private ChargingSessionStateMachine $sessions,
        private ChargeDetailRecordStateMachine $cdrs,
    ) {}

    public function generate(ChargingSession $session, bool $reviewApproved = false, string $outcome = 'complete'): ChargeDetailRecord
    {
        return DB::transaction(function () use ($session, $reviewApproved, $outcome): ChargeDetailRecord {
            $locked = ChargingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $cdr = ChargeDetailRecord::query()
                ->where('session_id', $locked->getKey())
                ->latest('version')
                ->lockForUpdate()
                ->first();
            if ($cdr === null || in_array($cdr->state, [ChargeDetailRecordState::Finalized, ChargeDetailRecordState::Superseded], true)) {
                $nextVersion = $cdr === null ? 1 : $cdr->version + 1;
                $cdr = ChargeDetailRecord::query()->create([
                    'session_id' => $locked->getKey(),
                    'version' => $nextVersion,
                    'state' => ChargeDetailRecordState::Pending,
                    'currency' => $locked->currency,
                    'energy_wh' => $locked->energy_wh,
                    'duration_seconds' => $locked->duration_seconds,
                ]);
                app(StateTransitionRecorder::class)->record('cdr', (string) $cdr->getKey(), null, ChargeDetailRecordState::Pending->value, 'cdr_created');
            }

            if ($cdr->state === ChargeDetailRecordState::Pending || $cdr->state === ChargeDetailRecordState::ReviewRequired) {
                $this->cdrs->transition($cdr, ChargeDetailRecordState::Generating, 'cdr_generation_started');
            }
            $anomalies = array_values(array_filter($locked->anomaly_flags, 'is_string'));
            $blocking = array_intersect($anomalies, [
                SessionAnomaly::TariffMissing->value,
                SessionAnomaly::MissingStartMeter->value,
                SessionAnomaly::MissingStopMeter->value,
                SessionAnomaly::InvalidMeterValue->value,
                SessionAnomaly::MeterReset->value,
                SessionAnomaly::ConflictingTransaction->value,
            ]);
            if ($blocking !== [] && ! $reviewApproved) {
                $this->cdrs->transition($cdr, ChargeDetailRecordState::ReviewRequired, 'cdr_blocking_anomaly', ['anomalies' => array_values($blocking)]);
                if ($locked->state === ChargingSessionState::Finalizing) {
                    $this->sessions->transition($locked, ChargingSessionState::ReviewRequired, 'cdr_review_required');
                }

                return $cdr->refresh();
            }

            $startedAt = $locked->started_at ?? $locked->requested_at;
            $rating = $this->tariffs->rate($locked->tariff_snapshot, new RatingInput(
                energyWh: (int) $locked->energy_wh,
                durationSeconds: (int) $locked->duration_seconds,
                parkingSeconds: (int) $locked->parking_seconds,
                idleSeconds: (int) $locked->idle_seconds,
                startedAt: $startedAt,
            ));
            $snapshot = [
                'session_id' => (string) $locked->getKey(),
                'tenant_id' => (string) $locked->tenant_id,
                'station_id' => (string) $locked->charging_station_id,
                'evse_id' => (string) $locked->evse_id,
                'connector_id' => (string) $locked->connector_id,
                'protocol' => (string) $locked->protocol,
                'protocol_transaction_id' => $locked->protocol_transaction_id,
                'started_at' => $locked->started_at?->utc()->toISOString(),
                'stopped_at' => $locked->stopped_at?->utc()->toISOString(),
                'energy_wh' => (int) $locked->energy_wh,
                'duration_seconds' => (int) $locked->duration_seconds,
                'parking_seconds' => (int) $locked->parking_seconds,
                'idle_seconds' => (int) $locked->idle_seconds,
                'tariff_snapshot' => $locked->tariff_snapshot,
                'tariff_snapshot_hash' => (string) $locked->tariff_snapshot_hash,
                'rating' => $rating->toArray(),
                'anomaly_flags' => $anomalies,
                'finalization_outcome' => $outcome,
            ];
            $hash = $this->hash($snapshot);
            $billable = $outcome !== 'unbillable';
            $cdr->forceFill([
                'snapshot' => $snapshot,
                'snapshot_hash' => $hash,
                'currency' => $rating->currency,
                'energy_wh' => $locked->energy_wh,
                'duration_seconds' => $locked->duration_seconds,
                'subtotal_minor' => $billable ? $rating->subtotalMinor : null,
                'discount_minor' => $billable ? $rating->discountMinor : null,
                'tax_minor' => $billable ? $rating->taxMinor : null,
                'total_minor' => $billable ? $rating->totalMinor : null,
                'generated_at' => now('UTC'),
                'finalized_at' => now('UTC'),
            ])->save();
            $this->cdrs->transition($cdr, ChargeDetailRecordState::Finalized, 'cdr_evidence_finalized');
            $locked->forceFill([
                'final_cost_minor' => $billable ? $rating->totalMinor : null,
                'estimated_cost_minor' => $billable ? $rating->totalMinor : $locked->estimated_cost_minor,
                'finalization_outcome' => $outcome,
            ])->save();
            if ($locked->state === ChargingSessionState::ReviewRequired) {
                $this->sessions->transition($locked, ChargingSessionState::Finalizing, 'manual_review_resolved');
            }
            $this->sessions->transition($locked, ChargingSessionState::Completed, 'cdr_finalized');

            return $cdr->refresh();
        });
    }

    /** @param array<string, mixed> $snapshot
     * @throws JsonException
     */
    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
