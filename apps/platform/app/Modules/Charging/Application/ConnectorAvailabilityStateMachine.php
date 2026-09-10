<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\ConnectorAvailability;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Integrations\Application\OutboxRecorder;
use DomainException;

final readonly class ConnectorAvailabilityStateMachine
{
    public function __construct(
        private StateTransitionRecorder $transitions,
        private OutboxRecorder $outbox,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function transition(
        ConnectorStatus $status,
        ConnectorAvailability $next,
        string $reason,
        array $evidence,
        ?string $eventId = null,
    ): void {
        $current = $status->status;
        if ($current === $next) {
            $status->forceFill(['observed_at' => $evidence['observed_at'] ?? now('UTC')])->save();

            return;
        }
        if (! $current->canTransitionTo($next)) {
            throw new DomainException("Connector cannot transition from {$current->value} to {$next->value}.");
        }

        $status->forceFill([
            'status' => $next,
            'observed_at' => $evidence['observed_at'] ?? now('UTC'),
        ])->save();
        $this->transitions->record('connector', (string) $status->connector_id, $current->value, $next->value, $reason, $evidence, $eventId);
        $this->outbox->record(
            'charging.connector.status_changed.v1',
            'connector',
            (string) $status->connector_id,
            ['previous_status' => $current->value, 'status' => $next->value, 'reason' => $reason],
            $eventId,
        );
    }
}
