<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Database\Factories\Modules\Maintenance\Domain\Models\MaintenancePriorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class MaintenancePriority extends TenantMaintenanceModel
{
    /** @use HasFactory<MaintenancePriorityFactory> */
    use HasFactory;

    protected $table = 'maintenance_priorities';

    protected function casts(): array
    {
        return ['rank' => 'integer', 'is_active' => 'boolean'];
    }
}
