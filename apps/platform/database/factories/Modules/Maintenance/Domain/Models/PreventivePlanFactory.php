<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Maintenance\Domain\Models;

use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Maintenance\Domain\Models\PreventivePlan;
use App\Modules\Maintenance\Domain\PreventiveTriggerType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PreventivePlan> */
final class PreventivePlanFactory extends Factory
{
    protected $model = PreventivePlan::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'plan_number' => 'PM-'.Str::ulid(),
            'site_id' => Site::factory(),
            'asset_type' => 'station',
            'asset_id' => fn (array $attributes): mixed => ChargingStation::factory()->create([
                'site_id' => $attributes['site_id'],
            ])->getKey(),
            'name' => fake()->sentence(4),
            'trigger_type' => PreventiveTriggerType::Date,
            'interval_value' => 86400,
            'next_due_at' => now('UTC')->addDay(),
            'priority_id' => MaintenancePriority::factory(),
            'is_active' => true,
        ];
    }
}
