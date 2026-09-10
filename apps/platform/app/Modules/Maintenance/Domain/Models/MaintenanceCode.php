<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

final class MaintenanceCode extends TenantMaintenanceModel
{
    protected $table = 'maintenance_codes';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
