<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 */
final class QrTopup extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime'];
    }
}
