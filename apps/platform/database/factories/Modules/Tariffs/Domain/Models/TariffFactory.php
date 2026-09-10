<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Tariffs\Domain\Models;

use App\Modules\Tariffs\Domain\Models\Tariff;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tariff> */
final class TariffFactory extends Factory
{
    protected $model = Tariff::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'currency' => 'USD',
            'status' => TariffStatus::Draft,
        ];
    }
}
