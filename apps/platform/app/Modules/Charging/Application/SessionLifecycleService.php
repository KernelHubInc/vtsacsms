<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SessionLifecycleService
{
    public function __construct(
        private ChargingSessionStateMachine $states,
        private ConnectorReservationService $reservations,
        private AuditRecorder $audit,
    ) {}

    public function cancel(ChargingSession $session, string $reason): void
    {
        DB::transaction(function () use ($session, $reason): void {
            $locked = ChargingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state->isPhysicallyActive() || $locked->started_at !== null) {
                throw new DomainException('A physically started session cannot be cancelled. Request a stop instead.');
            }
            $before = $locked->only(['state', 'cancellation_reason']);
            $locked->forceFill(['cancellation_reason' => $reason])->save();
            $this->states->transition($locked, ChargingSessionState::Cancelled, 'session_cancelled');
            $this->reservations->release((string) $locked->getKey(), 'session_cancelled', true);
            $this->audit->record(new AuditEntry(
                'charging.session.cancelled',
                'charging_session',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                reason: $reason,
                before: $before,
                after: $locked->fresh()->only(['state', 'cancellation_reason']),
            ));
        });
    }

    public function fail(ChargingSession $session, string $reason): void
    {
        DB::transaction(function () use ($session, $reason): void {
            $locked = ChargingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->started_at !== null) {
                throw new DomainException('A physically started session requires finalization, not failure.');
            }
            $locked->forceFill(['failure_reason' => $reason])->save();
            $this->states->transition($locked, ChargingSessionState::Failed, 'session_failed');
            $this->reservations->release((string) $locked->getKey(), 'session_failed', true);
        });
    }
}
