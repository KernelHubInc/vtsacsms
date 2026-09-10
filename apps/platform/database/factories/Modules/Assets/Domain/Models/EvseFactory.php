<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assets\Domain\Models;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Evse> */
final class EvseFactory extends Factory
{
    protected $model = Evse::class;

    public function definition(): array
    {
        return ['tenant_id' => app(CurrentTenant::class)->get()->tenantId, 'charging_station_id' => ChargingStation::factory(), 'evse_number' => fake()->numberBetween(1, 20), 'lifecycle_status' => AssetLifecycleStatus::Draft];
    }
}
