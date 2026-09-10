<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Maintenance\Domain\Models;

use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Maintenance\Domain\Models\SlaPolicy;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaPolicy> */
final class SlaPolicyFactory extends Factory
{
    protected $model = SlaPolicy::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'name' => fake()->unique()->words(3, true),
            'version' => 1,
            'priority_id' => MaintenancePriority::factory(),
            'acknowledge_seconds' => 3600,
            'resolve_seconds' => 14400,
            'pause_states' => ['awaiting_parts', 'awaiting_access'],
            'timezone' => 'UTC',
            'effective_from' => now('UTC')->subDay(),
            'effective_to' => null,
            'is_active' => true,
        ];
    }
}
