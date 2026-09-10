<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryBin> */
final class InventoryBinFactory extends Factory
{
    protected $model = InventoryBin::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'stock_location_id' => StockLocation::factory(),
            'warehouse_id' => fn (array $attributes): string => (string) StockLocation::query()
                ->whereKey($attributes['stock_location_id'])
                ->value('warehouse_id'),
            'code' => fake()->unique()->bothify('BIN-###'),
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
