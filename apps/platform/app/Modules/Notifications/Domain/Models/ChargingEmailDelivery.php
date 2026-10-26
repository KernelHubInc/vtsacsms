<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, int|string|null> $summary
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
        ];
    }
}
