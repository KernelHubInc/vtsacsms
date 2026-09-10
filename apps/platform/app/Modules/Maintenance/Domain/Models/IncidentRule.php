<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Database\Factories\Modules\Maintenance\Domain\Models\IncidentRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class IncidentRule extends TenantMaintenanceModel
{
    /** @use HasFactory<IncidentRuleFactory> */
    use HasFactory;

    protected $table = 'maintenance_incident_rules';

    protected function casts(): array
    {
        return [
            'creates_work_order' => 'boolean',
            'persistent_after_seconds' => 'integer',
            'recovery_after_seconds' => 'integer',
            'escalation_after_seconds' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
