<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Database\Factories\Modules\Inventory\Domain\Models\ItemCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ItemCategory extends TenantInventoryModel
{
    /** @use HasFactory<ItemCategoryFactory> */
    use HasFactory;

    protected $table = 'inventory_item_categories';

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /** @return HasMany<InventoryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'category_id');
    }
}
