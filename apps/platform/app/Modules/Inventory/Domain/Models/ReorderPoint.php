<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReorderPoint extends TenantInventoryModel
{
    protected $table = 'inventory_reorder_points';

    protected function casts(): array
    {
        return [
            'reorder_quantity_base' => 'integer',
            'minimum_quantity_base' => 'integer',
            'target_quantity_base' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
