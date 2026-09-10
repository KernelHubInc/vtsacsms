<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

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
