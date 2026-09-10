<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $round
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $submitted_at
 */
final class CountSheet extends TenantInventoryModel
{
    protected $table = 'inventory_count_sheets';

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CountLine::class, 'count_sheet_id');
    }
}
