<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Inventory\Domain\Models;

use App\Modules\Inventory\Domain\Models\ItemCategory;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItemCategory> */
final class ItemCategoryFactory extends Factory
{
    protected $model = ItemCategory::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'code' => fake()->unique()->bothify('CAT-###'),
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
