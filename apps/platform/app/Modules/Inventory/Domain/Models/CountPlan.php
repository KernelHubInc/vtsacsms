<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\CountStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CountStatus $status
 * @property bool $blind_count
 * @property CarbonImmutable $scheduled_for
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $posted_at
 */
final class CountPlan extends TenantInventoryModel
{
    protected $table = 'inventory_count_plans';

    protected function casts(): array
    {
        return [
            'status' => CountStatus::class,
            'blind_count' => 'boolean',
            'scheduled_for' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CountSheet, $this> */
    public function sheets(): HasMany
    {
        return $this->hasMany(CountSheet::class, 'count_plan_id');
    }
}
