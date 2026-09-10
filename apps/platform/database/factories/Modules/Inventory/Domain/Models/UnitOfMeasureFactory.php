<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UnitOfMeasure> */
final class UnitOfMeasureFactory extends Factory
{
    protected $model = UnitOfMeasure::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'code' => fake()->unique()->lexify('UOM-???'),
            'name' => fake()->unique()->word(),
            'dimension' => 'count',
            'base_multiplier' => 1,
            'is_active' => true,
        ];
    }
}
