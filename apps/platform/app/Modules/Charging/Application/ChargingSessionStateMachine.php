<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Events\ChargingSessionProjectionUpdated;
use App\Modules\Integrations\Application\OutboxRecorder;
use DomainException;

final readonly class ChargingSessionStateMachine
{
    public function __construct(
        private StateTransitionRecorder $transitions,
        private OutboxRecorder $outbox,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function transition(
        ChargingSession $session,
        ChargingSessionState $next,
        string $reason,
        array $evidence = [],
        ?string $eventId = null,
    ): void {
        $current = $session->state;
        if ($current === $next) {
            return;
        }
        if (! $current->canTransitionTo($next)) {
            throw new DomainException("Charging session cannot transition from {$current->value} to {$next->value}.");
        }

        $session->forceFill([
            'state' => $next,
            'aggregate_version' => (int) $session->aggregate_version + 1,
        ])->save();
        $this->transitions->record('session', (string) $session->getKey(), $current->value, $next->value, $reason, $evidence, $eventId);
        $this->outbox->record(
            $this->eventType($next),
            'charging_session',
            (string) $session->getKey(),
            [
                'state' => $next->value,
                'previous_state' => $current->value,
                'energy_wh' => (int) $session->energy_wh,
                'duration_seconds' => (int) $session->duration_seconds,
                'estimated_cost_minor' => $session->estimated_cost_minor,
                'final_cost_minor' => $session->final_cost_minor,
                'currency' => $session->currency,
                'reason' => $reason,
            ],
            $eventId,
        );
        ChargingSessionProjectionUpdated::dispatch($session->refresh());
    }

    private function eventType(ChargingSessionState $state): string
    {
        return match ($state) {
            ChargingSessionState::Requested => 'charging.session.requested.v1',
            ChargingSessionState::Authorizing => 'charging.session.authorization_started.v1',
            ChargingSessionState::Authorized => 'charging.session.authorized.v1',
            ChargingSessionState::Starting => 'charging.session.starting.v1',
            ChargingSessionState::Charging => 'charging.session.started.v1',
            ChargingSessionState::SuspendedByEv, ChargingSessionState::SuspendedByEvse => 'charging.session.suspended.v1',
            ChargingSessionState::Stopping => 'charging.session.stopping.v1',
            ChargingSessionState::Finalizing => 'charging.session.finalizing.v1',
            ChargingSessionState::ReviewRequired => 'charging.session.flagged_for_review.v1',
            ChargingSessionState::Completed => 'charging.session.completed.v1',
            ChargingSessionState::Failed => 'charging.session.failed.v1',
            ChargingSessionState::Cancelled => 'charging.session.cancelled.v1',
            ChargingSessionState::Expired => 'charging.session.expired.v1',
        };
    }
}
