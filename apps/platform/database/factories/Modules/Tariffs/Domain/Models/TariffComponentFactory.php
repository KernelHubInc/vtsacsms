<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Tariffs\Domain\Models;

use App\Modules\Tariffs\Domain\Models\TariffComponent;
use App\Modules\Tariffs\Domain\TariffDimension;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TariffComponent> */
final class TariffComponentFactory extends Factory
{
    protected $model = TariffComponent::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'tariff_version_id' => TariffVersionFactory::new(),
            'dimension' => TariffDimension::Energy,
            'price_minor' => 25,
            'unit_quantity' => 1000,
            'day_of_week_mask' => 127,
            'priority' => 0,
        ];
    }
}
