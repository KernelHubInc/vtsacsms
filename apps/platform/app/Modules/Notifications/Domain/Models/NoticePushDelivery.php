<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $attempted_at
 * @property CarbonImmutable|null $accepted_at
 */
final class NoticePushDelivery extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['installation_hash'];

    protected function casts(): array
    {
        return ['available_at' => 'immutable_datetime', 'attempted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
