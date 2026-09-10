<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\Models\ChargingStateTransition;
use App\Modules\Tenancy\Application\CurrentTenant;

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

        return ChargingStateTransition::query()->create([
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
    }
}
