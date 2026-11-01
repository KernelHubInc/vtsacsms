<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain\Models;

use App\Foundation\Database\PreventsFinancialMutation;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PlatformFeePolicy extends Model
{
    use BelongsToTenant, HasUlids, PreventsFinancialMutation;

    protected $fillable = ['enabled', 'amount_minor', 'waiver_threshold_minor', 'tax_rate_basis_points', 'beneficiary', 'effective_at'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'amount_minor' => 'integer', 'waiver_threshold_minor' => 'integer', 'tax_rate_basis_points' => 'integer', 'effective_at' => 'immutable_datetime'];
    }
}
