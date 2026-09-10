<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Warehouse> */
final class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'code' => fake()->unique()->bothify('WH-###'),
            'name' => fake()->unique()->words(2, true),
            'type' => 'warehouse',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ];
    }
}
