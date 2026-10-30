<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, int|string|null> $summary
 * @property CarbonImmutable|null $claimed_until
 * @property CarbonImmutable|null $last_attempt_at
 */
final class ChargingEmailDelivery extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'attempt_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'claimed_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
        ];
    }

    public function deliveryStatus(): string
    {
        if ($this->sent_at !== null) {
            return $this->message_id === null ? 'sent_legacy' : 'accepted';
        }

        if ($this->claimed_until?->isFuture()) {
            return 'sending';
        }

        return $this->attempt_count > 0 ? 'retrying' : 'queued';
    }
}
