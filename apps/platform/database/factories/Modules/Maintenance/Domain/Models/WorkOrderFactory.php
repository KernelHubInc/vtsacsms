<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Maintenance\Domain\Models;

use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Maintenance\Domain\WorkOrderState;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WorkOrder> */
final class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'work_order_number' => 'WO-'.Str::ulid(),
            'work_type' => 'corrective',
            'site_id' => Site::factory(),
            'asset_type' => 'station',
            'asset_id' => fn (array $attributes): mixed => ChargingStation::factory()->create([
                'site_id' => $attributes['site_id'],
            ])->getKey(),
            'priority_id' => MaintenancePriority::factory(),
            'state' => WorkOrderState::Reported,
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'created_by' => fn (): mixed => User::factory()->create()->public_id,
            'currency' => 'PHP',
            'aggregate_version' => 1,
        ];
    }
}
