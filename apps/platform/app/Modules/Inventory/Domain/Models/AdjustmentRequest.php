<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $posted_at
 */
final class AdjustmentRequest extends TenantInventoryModel
{
    protected $table = 'inventory_adjustment_requests';

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime'];
    }

    /** @return HasMany<AdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AdjustmentLine::class, 'adjustment_request_id');
    }
}
