<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Tariffs\Domain\Models;

use App\Modules\Tariffs\Domain\Models\TariffVersion;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Domain\TaxTreatment;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TariffVersion> */
final class TariffVersionFactory extends Factory
{
    protected $model = TariffVersion::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'tariff_id' => TariffFactory::new(),
            'version' => 1,
            'status' => TariffStatus::Draft,
            'effective_from' => now('UTC')->subDay(),
            'tax_treatment' => TaxTreatment::Inclusive,
            'timezone' => 'UTC',
        ];
    }
}
