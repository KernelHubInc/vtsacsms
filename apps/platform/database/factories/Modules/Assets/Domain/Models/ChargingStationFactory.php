<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assets\Domain\Models;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChargingStation> */
final class ChargingStationFactory extends Factory
{
    protected $model = ChargingStation::class;

    public function definition(): array
    {
        return ['tenant_id' => app(CurrentTenant::class)->get()->tenantId, 'site_id' => Site::factory(), 'name' => fake()->unique()->bothify('Station ##??'), 'charge_point_identity' => fake()->unique()->bothify('CP-####-????'), 'serial_number' => fake()->unique()->bothify('SN-########'), 'qr_identifier' => (string) Str::ulid(), 'lifecycle_status' => AssetLifecycleStatus::Draft, 'is_public' => false];
    }

    public function activePublic(): static
    {
        return $this->state(fn (): array => ['lifecycle_status' => AssetLifecycleStatus::Active, 'is_public' => true, 'commissioned_at' => now('UTC')]);
    }
}
