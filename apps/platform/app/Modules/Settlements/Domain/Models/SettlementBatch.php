<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SettlementBatch extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'prepared_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime'];
    }
}
