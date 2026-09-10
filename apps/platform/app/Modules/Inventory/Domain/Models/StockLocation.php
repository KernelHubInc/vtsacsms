<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Database\Factories\Modules\Inventory\Domain\Models\StockLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StockLocation extends TenantInventoryModel
{
    /** @use HasFactory<StockLocationFactory> */
    use HasFactory;

    protected $table = 'inventory_stock_locations';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<InventoryBin, $this> */
    public function bins(): HasMany
    {
        return $this->hasMany(InventoryBin::class);
    }
}
