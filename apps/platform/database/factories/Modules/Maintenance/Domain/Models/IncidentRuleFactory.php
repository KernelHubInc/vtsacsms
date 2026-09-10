<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Maintenance\Domain\Models;

use App\Modules\Maintenance\Domain\Models\IncidentRule;
use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IncidentRule> */
final class IncidentRuleFactory extends Factory
{
    protected $model = IncidentRule::class;

    public function definition(): array
    {
        $fault = fake()->unique()->bothify('FAULT_###');

        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'name' => "Open incident for {$fault}",
            'fault_code' => $fault,
            'asset_type' => 'connector',
            'priority_id' => MaintenancePriority::factory(),
            'sla_policy_id' => null,
            'creates_work_order' => true,
            'persistent_after_seconds' => 300,
            'recovery_after_seconds' => 60,
            'escalation_after_seconds' => 900,
            'is_active' => true,
        ];
    }
}
