<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockLocation> */
final class StockLocationFactory extends Factory
{
    protected $model = StockLocation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'warehouse_id' => Warehouse::factory(),
            'code' => fake()->unique()->bothify('LOC-###'),
            'name' => fake()->unique()->words(2, true),
            'custody_type' => 'available',
            'is_active' => true,
        ];
    }
}
