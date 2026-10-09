<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Foundation\Database\PreventsFinancialMutation;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PrepaidEntry extends Model
{
    use BelongsToTenant, HasUlids, PreventsFinancialMutation;

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
