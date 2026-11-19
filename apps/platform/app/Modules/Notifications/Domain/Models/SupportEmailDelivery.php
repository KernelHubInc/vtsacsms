<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SupportEmailDelivery extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['available_at' => 'immutable_datetime', 'claimed_until' => 'immutable_datetime', 'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime', 'attempt_count' => 'integer'];
    }
}
