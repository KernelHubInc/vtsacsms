<?php

declare(strict_types=1);

namespace App\Foundation\Audit;

use App\Foundation\Audit\Models\AuditEvent;
use JsonException;

final readonly class AuditChainVerifier
{
    public function __construct(private AuditHasher $hasher) {}

    /**
     * @throws JsonException
     */
    public function verify(string $tenantId): bool
    {
        $previousHash = null;
        $events = AuditEvent::query()
            ->where('tenant_id', $tenantId)
            ->oldest('occurred_at')
            ->oldest('id')
            ->get();

        foreach ($events as $event) {
            if ($event->previous_hash !== $previousHash) {
                return false;
            }

            $payload = [
                'tenant_id' => $event->tenant_id,
                'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'actor_type' => $event->actor_type->value,
                'actor_id' => $event->actor_id,
                'action' => $event->action,
                'target_type' => $event->target_type,
                'target_id' => $event->target_id,
                'result' => $event->result->value,
                'reason' => $event->reason,
                'changes' => $event->changes,
                'before' => $event->before,
                'after' => $event->after,
                'metadata' => $event->metadata,
                'source_ip' => $event->source_ip,
                'user_agent' => $event->user_agent,
                'correlation_id' => $event->correlation_id,
            ];

            if (! hash_equals($event->content_hash, $this->hasher->hash($previousHash, $payload))) {
                return false;
            }

            $previousHash = $event->content_hash;
        }

        return true;
    }
}
