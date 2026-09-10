<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain\Models;

use App\Foundation\Database\PreventsFinancialMutation;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ReconciliationLine extends Model
{
    use BelongsToTenant, HasUlids, PreventsFinancialMutation;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['safe_evidence' => 'array'];
    }
}
