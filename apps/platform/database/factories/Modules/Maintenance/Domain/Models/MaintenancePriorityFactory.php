<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Maintenance\Domain\Models;

use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenancePriority> */
final class MaintenancePriorityFactory extends Factory
{
    protected $model = MaintenancePriority::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'code' => fake()->unique()->bothify('P-###'),
            'name' => fake()->unique()->words(2, true),
            'rank' => fake()->numberBetween(1, 99),
            'color' => 'gray',
            'is_active' => true,
        ];
    }
}
