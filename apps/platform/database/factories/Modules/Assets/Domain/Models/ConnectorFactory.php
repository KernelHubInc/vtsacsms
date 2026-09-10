<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assets\Domain\Models;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Connector> */
final class ConnectorFactory extends Factory
{
    protected $model = Connector::class;

    public function definition(): array
    {
        return ['tenant_id' => app(CurrentTenant::class)->get()->tenantId, 'evse_id' => Evse::factory(), 'connector_standard_id' => ConnectorStandard::query()->available()->firstOrFail()->getKey(), 'charging_current_type_id' => ChargingCurrentType::query()->available()->firstOrFail()->getKey(), 'connector_number' => fake()->numberBetween(1, 4), 'qr_identifier' => (string) Str::ulid(), 'maximum_power_w' => 22000, 'lifecycle_status' => AssetLifecycleStatus::Draft];
    }
}
