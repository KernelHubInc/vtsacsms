<?php

declare(strict_types=1);

namespace App\Foundation\Audit;

use App\Foundation\Audit\Models\AuditEvent;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class AuditRecorder
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditHasher $hasher,
    ) {}

    /**
     * @throws JsonException
     */
    public function record(AuditEntry $entry): AuditEvent
    {
        $context = $this->currentTenant->get();

        return DB::transaction(function () use ($context, $entry): AuditEvent {
            Tenant::query()->whereKey($context->tenantId)->lockForUpdate()->firstOrFail();

            $previousHash = AuditEvent::query()
                ->where('tenant_id', $context->tenantId)
                ->latest('occurred_at')
                ->latest('id')
                ->value('content_hash');
            $occurredAt = CarbonImmutable::now('UTC')->startOfSecond();
            $userAgent = $entry->userAgent ?? request()->userAgent();
            $payload = [
                'tenant_id' => $context->tenantId,
                'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s.u\Z'),
                'actor_type' => $context->actorType->value,
                'actor_id' => $context->actorId,
                'action' => $entry->action,
                'target_type' => $entry->targetType,
                'target_id' => $entry->targetId,
                'result' => $entry->result->value,
                'reason' => $entry->reason,
                'changes' => $entry->changes,
                'before' => $entry->before,
                'after' => $entry->after,
                'metadata' => $entry->metadata,
                'source_ip' => $entry->sourceIp ?? request()->ip(),
                'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 500, ''),
                'correlation_id' => $context->correlationId,
            ];

            return AuditEvent::query()->create([
                ...$payload,
                'occurred_at' => $occurredAt,
                'previous_hash' => $previousHash,
                'content_hash' => $this->hasher->hash($previousHash, $payload),
            ]);
        });
    }
}
