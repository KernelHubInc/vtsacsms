<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Procurement\Domain\Models;

use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
final class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'code' => fake()->unique()->bothify('SUP-####'),
            'name' => fake()->unique()->company(),
            'email' => fake()->unique()->companyEmail(),
            'status' => 'active',
        ];
    }
}
