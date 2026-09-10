<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Database\Factories\Modules\Inventory\Domain\Models\InventoryBinFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryBin extends TenantInventoryModel
{
    /** @use HasFactory<InventoryBinFactory> */
    use HasFactory;

    protected $table = 'inventory_bins';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
