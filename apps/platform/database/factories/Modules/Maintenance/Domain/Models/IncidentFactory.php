<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Maintenance\Domain\Models;

use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Maintenance\Domain\IncidentState;
use App\Modules\Maintenance\Domain\Models\Incident;
use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Incident> */
final class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        $sourceKey = (string) Str::ulid();

        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'incident_number' => 'INC-'.Str::ulid(),
            'source' => 'manual',
            'source_key' => $sourceKey,
            'site_id' => Site::factory(),
            'asset_type' => 'station',
            'asset_id' => fn (array $attributes): mixed => ChargingStation::factory()->create([
                'site_id' => $attributes['site_id'],
            ])->getKey(),
            'fault_code' => 'MANUAL',
            'fingerprint' => hash('sha256', $sourceKey),
            'state' => IncidentState::Open,
            'priority_id' => MaintenancePriority::factory(),
            'title' => fake()->sentence(5),
            'details' => fake()->paragraph(),
            'first_observed_at' => now('UTC'),
            'last_observed_at' => now('UTC'),
        ];
    }
}
