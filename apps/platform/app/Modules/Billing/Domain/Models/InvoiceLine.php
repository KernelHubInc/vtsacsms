<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Foundation\Database\PreventsFinancialMutation;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceLine extends Model
{
    use BelongsToTenant, HasUlids, PreventsFinancialMutation;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax_snapshot' => 'array'];
    }

    /** @return BelongsTo<RatedCharge, $this> */
    public function ratedCharge(): BelongsTo
    {
        return $this->belongsTo(RatedCharge::class);
    }
}
