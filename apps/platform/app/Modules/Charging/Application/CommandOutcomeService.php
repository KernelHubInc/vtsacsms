<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargerCommand;

final readonly class CommandOutcomeService
{
    public function __construct(
        private ChargingSessionStateMachine $sessions,
        private ConnectorReservationService $reservations,
    ) {}

    public function apply(ChargerCommand $command): void
    {
        $session = $command->session()->lockForUpdate()->firstOrFail();
        if ($command->type === ChargerCommandType::RemoteStart) {
            if ($command->state === ChargerCommandState::Acknowledged) {
                return;
            }
            if (! $session->state->isTerminal() && ! $session->state->isPhysicallyActive()) {
                $session->failure_reason = 'remote_start_'.$command->state->value;
                $session->save();
                $this->sessions->transition($session, ChargingSessionState::Failed, 'remote_start_command_failed');
                $this->reservations->release((string) $command->session_id, 'remote_start_command_failed', true);
            }

            return;
        }

        if ($command->state === ChargerCommandState::Rejected && $session->state === ChargingSessionState::Stopping) {
            $previous = $command->expected_state['session_state'] ?? null;
            $resume = is_string($previous) ? ChargingSessionState::tryFrom($previous) : null;
            if ($resume !== null && $session->state->canTransitionTo($resume)) {
                $this->sessions->transition($session, $resume, 'remote_stop_rejected');
            }
        }
    }
}
