<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

final class MaintenanceSkill extends TenantMaintenanceModel
{
    protected $table = 'maintenance_skills';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
