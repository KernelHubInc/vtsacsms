<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Charging\Domain\ChargeDetailRecordState;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\ConnectorAvailability;
use PHPUnit\Framework\TestCase;

final class ChargingStateMachinesTest extends TestCase
{
    public function test_command_acknowledgement_is_not_a_charging_session_transition(): void
    {
        self::assertTrue(ChargerCommandState::Dispatched->canTransitionTo(ChargerCommandState::Acknowledged));
        self::assertFalse(ChargingSessionState::Starting->canTransitionTo(ChargingSessionState::Completed));
        self::assertTrue(ChargingSessionState::Starting->canTransitionTo(ChargingSessionState::Charging));
        self::assertFalse(ChargerCommandState::Acknowledged->canTransitionTo(ChargerCommandState::Dispatched));
    }

    public function test_terminal_sessions_never_reopen(): void
    {
        foreach ([
            ChargingSessionState::Completed,
            ChargingSessionState::Failed,
            ChargingSessionState::Cancelled,
            ChargingSessionState::Expired,
        ] as $terminal) {
            self::assertTrue($terminal->isTerminal());
            self::assertFalse($terminal->canTransitionTo(ChargingSessionState::Charging));
        }
    }

    public function test_cdr_and_connector_state_machines_define_safe_paths(): void
    {
        self::assertTrue(ChargeDetailRecordState::Pending->canTransitionTo(ChargeDetailRecordState::Generating));
        self::assertTrue(ChargeDetailRecordState::Generating->canTransitionTo(ChargeDetailRecordState::ReviewRequired));
        self::assertFalse(ChargeDetailRecordState::Finalized->canTransitionTo(ChargeDetailRecordState::Generating));
        self::assertTrue(ConnectorAvailability::Reserved->canTransitionTo(ConnectorAvailability::Occupied));
        self::assertTrue(ConnectorAvailability::Offline->canTransitionTo(ConnectorAvailability::Available));
    }
}
