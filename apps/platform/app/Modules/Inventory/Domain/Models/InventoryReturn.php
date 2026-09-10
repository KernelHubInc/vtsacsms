<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class InventoryReturn extends TenantInventoryModel
{
    protected $table = 'inventory_returns';

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime'];
    }

    /** @return HasMany<InventoryReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReturnLine::class);
    }
}
