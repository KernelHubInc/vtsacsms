<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Charging\Domain\Models\ChargingStateTransition;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class StateTransitionRecorder
{
    public function __construct(private CurrentTenant $tenant) {}

    /** @param array<string, mixed> $evidence */
    public function record(
        string $aggregateType,
        string $aggregateId,
        ?string $from,
        string $to,
        string $reason,
        array $evidence = [],
        ?string $eventId = null,
    ): ChargingStateTransition {
        $context = $this->tenant->get();

        return DB::transaction(function () use ($context, $aggregateType, $aggregateId, $from, $to, $reason, $evidence, $eventId): ChargingStateTransition {
            $transition = ChargingStateTransition::query()->create([
                'tenant_id' => $context->tenantId,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'from_state' => $from,
                'to_state' => $to,
                'reason' => $reason,
                'event_id' => $eventId,
                'actor_id' => $context->actorId,
                'evidence' => $evidence,
                'occurred_at' => now('UTC'),
            ]);
            app(AuditRecorder::class)->record(new AuditEntry(
                'charging.'.$aggregateType.'.state_changed', 'charging_'.$aggregateType, $aggregateId,
                AuditResult::Succeeded,
                metadata: ['from_state' => $from, 'to_state' => $to, 'reason_code' => $reason],
            ));

            return $transition;
        });
    }
}
