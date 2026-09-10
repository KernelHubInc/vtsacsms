<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\ItemCategory;
use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use App\Modules\Inventory\Domain\TrackingType;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryItem> */
final class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'category_id' => ItemCategory::factory(),
            'base_uom_id' => UnitOfMeasure::factory(),
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'tracking_type' => TrackingType::None,
            'valuation_method' => ValuationMethod::MovingAverage,
            'currency' => 'PHP',
            'standard_cost_minor' => fake()->numberBetween(100, 100_000),
            'is_active' => true,
        ];
    }
}
