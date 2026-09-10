<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Foundation\Database\PreventsFinancialMutation;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LedgerTransaction extends Model
{
    use BelongsToTenant, HasUlids, PreventsFinancialMutation;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime'];
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
