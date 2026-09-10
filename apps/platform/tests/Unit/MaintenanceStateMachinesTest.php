<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Maintenance\Domain\IncidentState;
use App\Modules\Maintenance\Domain\WorkOrderState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MaintenanceStateMachinesTest extends TestCase
{
    #[Test]
    public function work_order_transition_graph_is_explicit_and_terminal_states_are_final(): void
    {
        $allowed = [
            'reported' => ['triaged', 'canceled'],
            'triaged' => ['planned', 'assigned', 'canceled'],
            'planned' => ['scheduled', 'assigned', 'awaiting_parts', 'awaiting_access', 'awaiting_external', 'canceled'],
            'scheduled' => ['assigned', 'planned', 'awaiting_parts', 'awaiting_access', 'canceled'],
            'assigned' => ['in_progress', 'scheduled', 'awaiting_parts', 'awaiting_access', 'canceled'],
            'in_progress' => ['on_hold', 'awaiting_parts', 'awaiting_access', 'awaiting_external', 'awaiting_safety_clearance', 'completed'],
            'on_hold' => ['in_progress', 'planned', 'canceled'],
            'awaiting_parts' => ['in_progress', 'planned', 'canceled'],
            'awaiting_access' => ['in_progress', 'scheduled', 'canceled'],
            'awaiting_external' => ['in_progress', 'planned', 'canceled'],
            'awaiting_safety_clearance' => ['in_progress', 'completed'],
            'completed' => ['verification_required', 'verified', 'in_progress'],
            'verification_required' => ['verified', 'in_progress'],
            'verified' => ['closed', 'in_progress'],
            'closed' => [],
            'canceled' => [],
        ];

        foreach (WorkOrderState::cases() as $from) {
            foreach (WorkOrderState::cases() as $target) {
                self::assertSame(
                    in_array($target->value, $allowed[$from->value], true),
                    $from->canTransitionTo($target),
                    "{$from->value} -> {$target->value}",
                );
            }
        }

        self::assertTrue(WorkOrderState::Closed->isTerminal());
        self::assertTrue(WorkOrderState::Canceled->isTerminal());
        self::assertFalse(WorkOrderState::Verified->isTerminal());
    }

    #[Test]
    public function incident_active_states_are_distinct_from_recovery_and_dismissal(): void
    {
        foreach ([
            IncidentState::Open,
            IncidentState::Acknowledged,
            IncidentState::Mitigated,
            IncidentState::Recurrent,
        ] as $state) {
            self::assertTrue($state->active(), $state->value);
        }

        self::assertFalse(IncidentState::Resolved->active());
        self::assertFalse(IncidentState::Dismissed->active());
    }

    #[Test]
    public function only_hold_states_are_eligible_for_policy_driven_sla_pausing(): void
    {
        $eligible = [
            WorkOrderState::OnHold,
            WorkOrderState::AwaitingParts,
            WorkOrderState::AwaitingAccess,
            WorkOrderState::AwaitingExternal,
            WorkOrderState::AwaitingSafetyClearance,
        ];

        foreach (WorkOrderState::cases() as $state) {
            self::assertSame(in_array($state, $eligible, true), $state->pausesSla(), $state->value);
        }
    }
}
