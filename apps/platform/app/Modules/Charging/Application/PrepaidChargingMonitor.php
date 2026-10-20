<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Charging\Domain\Models\AuthorizationToken;
use App\Modules\Charging\Domain\Models\ChargeDetailRecord;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Charging\Domain\Models\InboundOcppEvent;
use App\Modules\Charging\Domain\Models\MeterReading;
use App\Modules\Charging\Domain\Models\StationConnection;
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
            if ($this->cancelConfirmedIdleStart($session)) {
                $this->wallet->settle((string) $owner->subject_id, 'live', $sessionId, 0, $owner->payer_account_id);
                $owner->forceFill(['payment_state' => 'not_required', 'settled_at' => now('UTC')])->save();

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
                $this->wallet->settle((string) $owner->subject_id, 'live', $sessionId, (int) $cdr->total_minor, $owner->payer_account_id);
                $owner->forceFill(['payment_state' => 'captured', 'settled_at' => now('UTC')])->save();

                return;
            }
            if (in_array($session->state->value, ['failed', 'cancelled', 'expired'], true) && $session->started_at === null) {
                $command = ChargerCommand::query()->where('session_id', $sessionId)->where('type', 'remote_start')->first();
                $definitive = $command !== null && ($command->state->value === 'rejected'
                    || ($command->dispatched_at === null && $command->state->isTerminal()));
                if ($definitive) {
                    $this->wallet->settle((string) $owner->subject_id, 'live', $sessionId, 0, $owner->payer_account_id);
                    $owner->forceFill(['payment_state' => 'not_required', 'settled_at' => now('UTC')])->save();
                } else {
                    $owner->forceFill(['payment_state' => 'requires_action'])->save();
                }

                return;
            }
            if ($session->cancellation_reason === 'customer_cancel_requested'
                && in_array($session->state->value, ['starting', 'charging', 'suspended_by_ev', 'suspended_by_evse'], true)) {
                $attempts = ChargerCommand::query()->where('session_id', $sessionId)->where('reason_code', 'driver.cancel_pending_start')->count();
                if ($session->protocol_transaction_id === null || $attempts >= 3) {
                    $owner->forceFill(['payment_state' => 'requires_action'])->save();
                } else {
                    app(RemoteStopService::class)->request($session, 'cancel-start-stop-'.$sessionId.'-'.$attempts, cancelPendingStart: true);
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

    private function cancelConfirmedIdleStart(ChargingSession $session): bool
    {
        if ($session->cancellation_reason !== 'customer_cancel_requested'
            || ! in_array($session->state->value, ['requested', 'authorizing', 'authorized', 'starting'], true)
            || $session->started_at !== null || $session->protocol_transaction_id !== null
            || $session->meter_start_wh !== null || $session->energy_wh !== 0
            || $session->start_deadline_at === null) {
            return false;
        }
        $command = ChargerCommand::query()->where('session_id', $session->getKey())->where('type', 'remote_start')->first();
        if ($command === null || ! $command->state->isTerminal()) {
            return false;
        }
        // An elapsed deadline alone cannot prove the charger stayed idle. Allow time for queued evidence, too.
        $after = $command->expires_at->max($session->start_deadline_at)->addMinute();
        $signal = ConnectorStatus::query()->where('connector_id', $session->connector_id)->lockForUpdate()->first();
        $connection = StationConnection::query()->where('charging_station_id', $session->charging_station_id)->first();
        if ($after->isFuture() || $signal === null || $connection === null
            || $signal->status->value !== 'available' || $signal->reported_status !== 'available'
            || $signal->ocpp_connection_id !== $connection->connection_id
            || $signal->last_ocpp_event_at === null || $signal->last_ocpp_event_at->lte($after)
            || $signal->last_ocpp_event_at->isFuture() || $signal->last_ocpp_event_at->lt($connection->connected_at)
            || app(StationConnectionQuery::class)->status($connection->connected, $connection->last_seen_at->toIso8601String()) !== 'online'
            || MeterReading::query()->where('session_id', $session->getKey())->exists()
            || InboundOcppEvent::query()->where('aggregate_id', $session->charging_station_id)
                ->where('received_at', '>=', $session->requested_at)->where('outcome', '!=', 'processed')
                ->whereIn('event_type', ['gateway.ocpp.start_transaction.received.v1', 'gateway.ocpp.stop_transaction.received.v1',
                    'gateway.ocpp.transaction_event.received.v1', 'gateway.ocpp.meter_values.received.v1'])->exists()) {
            return false;
        }
        AuthorizationToken::query()->where('session_id', $session->getKey())->whereNull('revoked_at')
            ->update(['status' => 'revoked', 'revoked_at' => now('UTC')]);
        app(SessionLifecycleService::class)->cancel($session, 'customer_cancelled_after_idle_confirmation', [
            'command_id' => (string) $command->getKey(), 'command_state' => $command->state->value,
            'connection_id' => $connection->connection_id, 'idle_observed_at' => $signal->last_ocpp_event_at->toIso8601String(),
        ]);

        return true;
    }
}
