<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Integrations\Application\OutboxRecorder;
use DomainException;

final readonly class ChargerCommandStateMachine
{
    public function __construct(
        private StateTransitionRecorder $transitions,
        private OutboxRecorder $outbox,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function transition(
        ChargerCommand $command,
        ChargerCommandState $next,
        string $reason,
        array $evidence = [],
    ): void {
        $current = $command->state;
        if ($current === $next) {
            return;
        }
        if (! $current->canTransitionTo($next)) {
            throw new DomainException("Charger command cannot transition from {$current->value} to {$next->value}.");
        }

        $command->forceFill([
            'state' => $next,
            'dispatched_at' => $next === ChargerCommandState::Dispatched ? now('UTC') : $command->dispatched_at,
            'acknowledged_at' => in_array($next, [ChargerCommandState::Acknowledged, ChargerCommandState::Rejected], true) ? now('UTC') : $command->acknowledged_at,
        ])->save();
        $this->transitions->record('command', (string) $command->getKey(), $current->value, $next->value, $reason, $evidence);
        if ($command->type->isStationControl()) {
            $this->outbox->record('charging.station_command.state_changed.v1', 'charger_command', (string) $command->getKey(), [
                'station_id' => $command->charging_station_id, 'connector_id' => $command->connector_id,
                'type' => $command->type->value, 'state' => $next->value, 'reason' => $reason,
            ]);
            app(AuditRecorder::class)->record(new AuditEntry(
                'charging.station_command.'.$next->value, 'charger_command', (string) $command->getKey(),
                AuditResult::Succeeded, metadata: ['station_id' => $command->charging_station_id, 'reason' => $reason],
            ));

            return;
        }
        $this->outbox->record(
            $this->eventType($next),
            'charger_command',
            (string) $command->getKey(),
            [
                'session_id' => (string) $command->session_id,
                'type' => $command->type->value,
                'state' => $next->value,
                'reason' => $reason,
            ],
        );
    }

    private function eventType(ChargerCommandState $state): string
    {
        return match ($state) {
            ChargerCommandState::Requested => 'charging.command.requested.v1',
            ChargerCommandState::Dispatched => 'charging.command.dispatched.v1',
            ChargerCommandState::Acknowledged => 'charging.command.completed.v1',
            ChargerCommandState::Rejected,
            ChargerCommandState::TimedOut,
            ChargerCommandState::DeliveryUnknown,
            ChargerCommandState::Expired => 'charging.command.failed.v1',
        };
    }
}
