<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use App\Modules\Maintenance\Domain\PreventiveTriggerType;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Maintenance\Domain\Models\PreventivePlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property PreventiveTriggerType $trigger_type
 * @property int $interval_value
 * @property int $baseline_runtime_seconds
 * @property int $baseline_session_count
 * @property int $baseline_energy_wh
 * @property CarbonImmutable|null $next_due_at
 * @property CarbonImmutable|null $last_generated_at
 */
final class PreventivePlan extends TenantMaintenanceModel
{
    /** @use HasFactory<PreventivePlanFactory> */
    use HasFactory;

    protected $table = 'maintenance_preventive_plans';

    protected function casts(): array
    {
        return [
            'trigger_type' => PreventiveTriggerType::class,
            'interval_value' => 'integer',
            'next_due_at' => 'immutable_datetime',
            'baseline_runtime_seconds' => 'integer',
            'baseline_session_count' => 'integer',
            'baseline_energy_wh' => 'integer',
            'is_active' => 'boolean',
            'last_generated_at' => 'immutable_datetime',
        ];
    }
}
