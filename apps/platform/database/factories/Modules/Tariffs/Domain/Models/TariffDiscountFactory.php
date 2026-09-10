<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Tariffs\Domain\Models;

use App\Modules\Tariffs\Domain\DiscountKind;
use App\Modules\Tariffs\Domain\Models\TariffDiscount;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TariffDiscount> */
final class TariffDiscountFactory extends Factory
{
    protected $model = TariffDiscount::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'tariff_version_id' => TariffVersionFactory::new(),
            'name' => 'Test promotion',
            'kind' => DiscountKind::Percentage,
            'value' => 1000,
            'is_automatic' => true,
        ];
    }
}
