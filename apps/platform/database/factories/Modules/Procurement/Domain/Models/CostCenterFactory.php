<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Procurement\Domain\Models;

use App\Modules\Procurement\Domain\Models\CostCenter;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CostCenter> */
final class CostCenterFactory extends Factory
{
    protected $model = CostCenter::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'code' => fake()->unique()->bothify('CC-####'),
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }
}
