<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\TrackingType;
use App\Modules\Inventory\Domain\ValuationMethod;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Inventory\Domain\Models\InventoryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TrackingType $tracking_type
 * @property ValuationMethod $valuation_method
 * @property int|null $standard_cost_minor
 * @property string $currency
 * @property CarbonImmutable|null $archived_at
 */
final class InventoryItem extends TenantInventoryModel
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected $table = 'inventory_items';

    protected function casts(): array
    {
        return [
            'tracking_type' => TrackingType::class,
            'valuation_method' => ValuationMethod::class,
            'standard_cost_minor' => 'integer',
            'is_active' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ItemCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /** @return BelongsTo<UnitOfMeasure, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }
}
