<?php

declare(strict_types=1);

namespace App\Foundation\Audit\Models;

use App\Foundation\Audit\AuditResult;
use App\Modules\Identity\Domain\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string|null $tenant_id
 * @property CarbonImmutable $occurred_at
 * @property ActorType $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string $target_type
 * @property string|null $target_id
 * @property AuditResult $result
 * @property string|null $reason
 * @property array<string, mixed> $changes
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property array<string, mixed> $metadata
 * @property string|null $source_ip
 * @property string|null $user_agent
 * @property string $correlation_id
 * @property string|null $previous_hash
 * @property string $content_hash
 */
final class AuditEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'occurred_at',
        'actor_type',
        'actor_id',
        'action',
        'target_type',
        'target_id',
        'result',
        'reason',
        'changes',
        'before',
        'after',
        'metadata',
        'source_ip',
        'user_agent',
        'correlation_id',
        'previous_hash',
        'content_hash',
    ];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Audit events are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Audit events are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'actor_type' => ActorType::class,
            'result' => AuditResult::class,
            'changes' => 'array',
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
        ];
    }
}
