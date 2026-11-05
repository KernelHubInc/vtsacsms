<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ChargingStateTransition;
use App\Modules\Charging\Domain\Models\MeterReading;
use App\Modules\Charging\Domain\Models\StationConnection;
use App\Modules\Charging\Domain\SessionAnomaly;

final readonly class AutomaticStopRecovery
{
    public function recover(ChargingSession $session): void
    {
        $command = $this->latest($session);
        if ($command === null || ! $this->isAutomatic($command)) {
            return;
        }
        if ($command->state === ChargerCommandState::Requested && $command->expires_at->lte(now('UTC'))) {
            app(ChargerCommandStateMachine::class)->transition($command, ChargerCommandState::Expired, 'command_deadline_elapsed');
        }
        if ($this->retryable($session, $command)) {
            $cancel = $command->reason_code === 'driver.cancel_pending_start';
            $attempts = $this->attempts($session, $command);
            app(RemoteStopService::class)->request($session, ($cancel ? 'cancel-start-stop-' : 'budget-stop-').$session->getKey().'-'.$attempts,
                spendingLimit: ! $cancel, cancelPendingStart: $cancel);

            return;
        }
        if ($this->attempts($session, $command) < max(1, (int) config('mobile_charging.stop_maximum_attempts', 3))
            && $command->created_at->addSeconds(max(30, (int) config('mobile_charging.stop_confirmation_seconds', 60)))->isFuture()) {
            return;
        }
        $session->forceFill(['anomaly_flags' => array_values(array_unique([
            ...$session->anomaly_flags, SessionAnomaly::RemoteStopUnconfirmed->value,
        ]))])->save();
        if (! ChargingStateTransition::query()->where('aggregate_type', 'session')->where('aggregate_id', $session->getKey())
            ->where('reason', 'automatic_stop_unconfirmed')->exists()) {
            app(StateTransitionRecorder::class)->record('session', (string) $session->getKey(), $session->state->value,
                $session->state->value, 'automatic_stop_unconfirmed', [
                    'command_id' => (string) $command->getKey(), 'command_state' => $command->state->value,
                    'attempts' => $this->attempts($session, $command),
                ]);
        }
    }

    public function retryable(ChargingSession $session, ?ChargerCommand $command = null): bool
    {
        $command ??= $this->latest($session);
        if ($command === null || ! $this->isAutomatic($command) || ! $command->targetsTransaction($session)
            || ! $command->state->isTerminal() || $session->stopped_at !== null
            || ! $session->state->isPhysicallyActive()
            || $this->attempts($session, $command) >= max(1, (int) config('mobile_charging.stop_maximum_attempts', 3))
            || $command->created_at->addSeconds(max(10, (int) config('mobile_charging.stop_retry_seconds', 30)))->isFuture()) {
            return false;
        }
        if (($command->state === ChargerCommandState::Expired && $command->dispatched_at === null)
            || $command->state === ChargerCommandState::Rejected) {
            return true;
        }
        // An accepted/ambiguous stop can already have succeeded. Retry only on fresh, continuing energy flow.
        $after = ($command->acknowledged_at ?? $command->expires_at)
            ->addSeconds(max(30, (int) config('mobile_charging.stop_confirmation_seconds', 60)));
        $connection = StationConnection::query()->where('charging_station_id', $session->charging_station_id)->first();
        if ($after->isFuture() || $connection === null
            || app(StationConnectionQuery::class)->status($connection->connected, $connection->last_seen_at->toISOString()) !== 'online') {
            return false;
        }
        $power = MeterReading::query()->where('session_id', $session->getKey())->where('measurand', 'Power.Active.Import')
            ->where('unit', 'W')->whereNull('phase')->where('is_out_of_order', false)->where('is_meter_reset', false)
            ->where('sampled_at', '>', $after->max($connection->connected_at))->where('sampled_at', '<=', now('UTC'))
            ->where('received_at', '>=', $connection->connected_at)->latest('sampled_at')->first();

        if ($power !== null && $power->value > 0
            && $power->sampled_at->addSeconds((int) config('mobile_charging.meter_timeout_seconds'))->gte(now('UTC'))) {
            return true;
        }
        $energy = MeterReading::query()->where('session_id', $session->getKey())->where('measurand', 'Energy.Active.Import.Register')
            ->where('unit', 'Wh')->whereNull('phase')->where('is_out_of_order', false)->where('is_meter_reset', false)
            ->where('sampled_at', '>', $after->max($connection->connected_at))->where('sampled_at', '<=', now('UTC'))
            ->where('received_at', '>=', $connection->connected_at)->latest('sampled_at')->limit(2)->get();

        return $energy->count() === 2 && $energy[0]->sampled_at->gt($energy[1]->sampled_at)
            && $energy[0]->value > $energy[1]->value
            && $energy[0]->sampled_at->addSeconds((int) config('mobile_charging.meter_timeout_seconds'))->gte(now('UTC'));
    }

    private function latest(ChargingSession $session): ?ChargerCommand
    {
        return ChargerCommand::query()->where('session_id', $session->getKey())->where('type', 'remote_stop')->latest('id')->first();
    }

    private function isAutomatic(ChargerCommand $command): bool
    {
        return in_array($command->reason_code, ['prepaid.spending_limit_or_stale_meter', 'driver.cancel_pending_start'], true);
    }

    private function attempts(ChargingSession $session, ChargerCommand $command): int
    {
        return ChargerCommand::query()->where('session_id', $session->getKey())->where('type', 'remote_stop')
            ->where('reason_code', $command->reason_code)->count();
    }
}
