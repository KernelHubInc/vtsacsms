<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ChargingSessionReview;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ManualSessionReviewService
{
    public function __construct(
        private CurrentTenant $tenant,
        private ChargingSessionStateMachine $states,
        private ChargeDetailRecordGenerator $cdrs,
        private AuditRecorder $audit,
    ) {}

    public function open(ChargingSession $session, string $reasonCode, ?string $notes = null): ChargingSessionReview
    {
        return DB::transaction(function () use ($session, $reasonCode, $notes): ChargingSessionReview {
            $locked = ChargingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state === ChargingSessionState::Finalizing) {
                $this->states->transition($locked, ChargingSessionState::ReviewRequired, 'manual_review_opened');
            } elseif ($locked->state !== ChargingSessionState::ReviewRequired) {
                throw new DomainException('Only finalizing or review-required sessions can enter manual review.');
            }
            $review = ChargingSessionReview::query()->create([
                'session_id' => $locked->getKey(),
                'status' => 'open',
                'reason_code' => $reasonCode,
                'notes' => $notes,
                'opened_by' => $this->tenant->get()->actorId,
                'opened_at' => now('UTC'),
            ]);
            $this->audit->record(new AuditEntry(
                'charging.session_review.opened',
                'charging_session',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                reason: $reasonCode,
                metadata: ['review_id' => (string) $review->getKey()],
            ));

            return $review;
        });
    }

    /** @param array<string, int> $adjustments */
    public function resolve(
        ChargingSession $session,
        string $decision,
        string $notes,
        array $adjustments = [],
    ): ChargingSessionReview {
        if (! in_array($decision, ['complete', 'estimated', 'unbillable'], true)) {
            throw new DomainException('Unsupported manual review decision.');
        }
        foreach ($adjustments as $key => $value) {
            if (! in_array($key, ['energy_wh', 'duration_seconds', 'parking_seconds', 'idle_seconds'], true) || $value < 0) {
                throw new DomainException('Manual review adjustments must be non-negative supported measurements.');
            }
        }

        $review = DB::transaction(function () use ($session, $decision, $notes, $adjustments): ChargingSessionReview {
            $locked = ChargingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state !== ChargingSessionState::ReviewRequired) {
                throw new DomainException('The session is not awaiting manual review.');
            }
            $review = ChargingSessionReview::query()
                ->where('session_id', $locked->getKey())
                ->where('status', 'open')
                ->lockForUpdate()
                ->latest('opened_at')
                ->first();
            if ($review === null) {
                $review = ChargingSessionReview::query()->create([
                    'session_id' => $locked->getKey(),
                    'status' => 'open',
                    'reason_code' => 'automatic_anomaly_review',
                    'opened_by' => null,
                    'opened_at' => now('UTC'),
                ]);
            }
            $before = $locked->only(['energy_wh', 'duration_seconds', 'parking_seconds', 'idle_seconds', 'state']);
            if ($adjustments !== []) {
                $locked->forceFill($adjustments)->save();
            }
            $review->forceFill([
                'status' => 'resolved',
                'notes' => $notes,
                'resolved_by' => $this->tenant->get()->actorId,
                'resolved_at' => now('UTC'),
                'decision' => $decision,
                'adjustments' => $adjustments,
            ])->save();
            $this->audit->record(new AuditEntry(
                'charging.session_review.resolved',
                'charging_session',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                reason: $decision,
                before: $before,
                after: $locked->fresh()->only(['energy_wh', 'duration_seconds', 'parking_seconds', 'idle_seconds', 'state']),
                metadata: ['review_id' => (string) $review->getKey(), 'notes' => $notes],
            ));

            return $review;
        });

        $this->cdrs->generate($session->refresh(), true, $decision);

        return $review->refresh();
    }
}
