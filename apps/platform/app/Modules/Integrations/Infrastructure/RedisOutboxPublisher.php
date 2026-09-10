<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Infrastructure;

use App\Modules\Integrations\Domain\Models\OutboxEvent;
use Illuminate\Support\Facades\Redis;

final class RedisOutboxPublisher
{
    public function publish(OutboxEvent $event): void
    {
        $json = json_encode([
            'event_id' => (string) $event->event_id,
            'event_type' => (string) $event->event_type,
            'schema_version' => (int) $event->schema_version,
            'occurred_at' => $event->occurred_at->utc()->toISOString(),
            'tenant_id' => (string) $event->tenant_id,
            'aggregate_type' => (string) $event->aggregate_type,
            'aggregate_id' => (string) $event->aggregate_id,
            'correlation_id' => (string) $event->correlation_id,
            'causation_id' => $event->causation_id,
            'data' => $event->data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        Redis::connection('ocpp_gateway')->command('xadd', [
            (string) config('services.integration_events.stream'),
            '*',
            ['event' => $json],
        ]);
    }
}
