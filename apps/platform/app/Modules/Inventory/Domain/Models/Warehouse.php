<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Locations\Domain\Models\Site;
use Database\Factories\Modules\Inventory\Domain\Models\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Warehouse extends TenantInventoryModel
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    protected $table = 'inventory_warehouses';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<StockLocation, $this> */
    public function stockLocations(): HasMany
    {
        return $this->hasMany(StockLocation::class);
    }

    /** @return HasMany<InventoryBin, $this> */
    public function bins(): HasMany
    {
        return $this->hasMany(InventoryBin::class);
    }
}
