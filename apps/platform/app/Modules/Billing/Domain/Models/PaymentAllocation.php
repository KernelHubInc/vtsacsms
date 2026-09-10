<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Foundation\Database\PreventsFinancialMutation;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PaymentAllocation extends Model
{
    use BelongsToTenant, HasUlids, PreventsFinancialMutation;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allocated_at' => 'immutable_datetime'];
    }
}
