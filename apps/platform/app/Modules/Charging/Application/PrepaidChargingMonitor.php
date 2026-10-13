<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Charging\Domain\Models\ChargeDetailRecord;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Charging\Domain\Models\MeterReading;
use App\Modules\Tariffs\Application\RatingInput;
use App\Modules\Tariffs\Application\TariffEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class PrepaidChargingMonitor
{
    public function __construct(private PrepaidWallet $wallet) {}

    public function reconcile(string $sessionId): void
    {
        DB::transaction(function () use ($sessionId): void {
            $session = ChargingSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $owner = CustomerChargingSession::query()->where('session_id', $sessionId)->lockForUpdate()->firstOrFail();
            if ($owner->settled_at !== null) {
                return;
            }
            if ($session->failure_reason === 'prepaid_start_unconfirmed' || $session->state->value === 'review_required') {
                $owner->forceFill(['payment_state' => 'requires_action'])->save();
            }
            if ($session->state->value === 'stopping' && ChargerCommand::query()->where('session_id', $sessionId)
                ->where('type', 'remote_stop')->where('created_at', '<=', now('UTC')->subSeconds(120))->exists()) {
                $owner->forceFill(['payment_state' => 'requires_action'])->save();
            }
            if ($session->state->value === 'completed') {
                $cdr = ChargeDetailRecord::query()->where('session_id', $sessionId)->where('state', 'finalized')->latest('version')->first();
                if ($cdr === null || $cdr->total_minor === null || $cdr->currency !== 'PHP'
                    || $cdr->total_minor > $owner->budget_minor || $session->finalization_outcome !== 'complete') {
                    $owner->forceFill(['payment_state' => 'requires_action'])->save();

                    return;
                }
                $this->wallet->settle((string) $owner->subject_id, 'live', $sessionId, (int) $cdr->total_minor);
                $owner->forceFill(['payment_state' => 'captured', 'settled_at' => now('UTC')])->save();

                return;
            }
            if (in_array($session->state->value, ['failed', 'cancelled', 'expired'], true) && $session->started_at === null) {
                $command = ChargerCommand::query()->where('session_id', $sessionId)->where('type', 'remote_start')->first();
                $definitive = $command !== null && ($command->state->value === 'rejected'
                    || ($command->dispatched_at === null && $command->state->isTerminal()));
                if ($definitive) {
                    $this->wallet->settle((string) $owner->subject_id, 'live', $sessionId, 0);
                    $owner->forceFill(['payment_state' => 'not_required', 'settled_at' => now('UTC')])->save();
                } else {
                    $owner->forceFill(['payment_state' => 'requires_action'])->save();
                }

                return;
            }
            if (! in_array($session->state->value, ['charging', 'suspended_by_ev', 'suspended_by_evse'], true) || $session->started_at === null) {
                return;
            }
            $latest = MeterReading::query()->where('session_id', $sessionId)->where('measurand', 'Energy.Active.Import.Register')
                ->where('unit', 'Wh')->whereNull('phase')->max('sampled_at');
            $lastAt = $latest === null ? $session->started_at : CarbonImmutable::parse($latest);
            $stale = $lastAt->addSeconds((int) config('mobile_charging.meter_timeout_seconds'))->isPast();
            $bufferSeconds = (int) config('mobile_charging.stop_buffer_seconds');
            $futureEnergy = (int) $session->energy_wh + intdiv($session->connector_maximum_power_w * $bufferSeconds + 3599, 3600);
            $future = app(TariffEngine::class)->rate($session->tariff_snapshot, new RatingInput(
                $futureEnergy, (int) $session->duration_seconds + $bufferSeconds, (int) $session->parking_seconds,
                (int) $session->idle_seconds, $session->started_at, (int) $session->energy_flow_seconds + $bufferSeconds,
            ));
            $components = $session->tariff_snapshot['components'] ?? [];
            if (is_array($components) && in_array('time', array_column($components, 'dimension'), true)) {
                $lastPower = MeterReading::query()->where('session_id', $sessionId)->where('measurand', 'Power.Active.Import')
                    ->where('unit', 'W')->whereNull('phase')->max('sampled_at');
                $powerAt = $lastPower === null ? $session->started_at : CarbonImmutable::parse($lastPower);
                $stale = $stale || $powerAt->addSeconds((int) config('mobile_charging.meter_timeout_seconds'))->isPast();
            }
            $usagePriced = is_array($components) && array_intersect(['energy', 'time'], array_column($components, 'dimension')) !== [];
            if (! $stale && $session->failure_reason !== 'prepaid_start_unconfirmed' && (! $usagePriced || $future->totalMinor < $owner->budget_minor)) {
                return;
            }
            $attempts = ChargerCommand::query()->where('session_id', $sessionId)->where('idempotency_key', 'like', 'budget-stop-%')->count();
            if ($attempts >= 3 || $session->protocol_transaction_id === null) {
                $owner->forceFill(['payment_state' => 'requires_action'])->save();

                return;
            }
            // This service identity acts under the driver's recorded spending-limit consent.
            app(RemoteStopService::class)->request($session, 'budget-stop-'.$sessionId.'-'.$attempts, true);
        });
    }
}
