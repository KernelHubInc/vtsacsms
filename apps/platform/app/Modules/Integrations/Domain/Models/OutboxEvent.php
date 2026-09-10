<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $event_id
 * @property string $event_type
 * @property int $schema_version
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $correlation_id
 * @property string|null $causation_id
 * @property array<string, mixed> $data
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $published_at
 * @property int $publish_attempts
 */
final class OutboxEvent extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'integration_outbox_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
