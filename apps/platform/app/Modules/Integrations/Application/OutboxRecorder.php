<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application;

use App\Modules\Integrations\Domain\Models\OutboxEvent;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Str;

final readonly class OutboxRecorder
{
    public function __construct(private CurrentTenant $tenant) {}

    /** @param array<string, mixed> $data */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $data,
        ?string $causationId = null,
    ): OutboxEvent {
        $context = $this->tenant->get();

        return OutboxEvent::query()->create([
            'tenant_id' => $context->tenantId,
            'event_id' => (string) Str::ulid(),
            'event_type' => $eventType,
            'schema_version' => 1,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'correlation_id' => $context->correlationId,
            'causation_id' => $causationId,
            'data' => $data,
            'occurred_at' => now('UTC'),
        ]);
    }
}
