<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property CarbonImmutable $inspected_at
 * @property CarbonImmutable|null $approved_at
 */
final class MaintenanceInspection extends TenantMaintenanceModel
{
    protected $table = 'maintenance_inspections';

    protected function casts(): array
    {
        return ['inspected_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime'];
    }
}
