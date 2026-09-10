<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property int $duration_seconds
 * @property int $rate_minor_per_hour
 * @property int $total_minor
 * @property CarbonImmutable|null $started_at
 */
final class MaintenanceTimeEntry extends TenantMaintenanceModel
{
    protected $table = 'maintenance_time_entries';

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'rate_minor_per_hour' => 'integer',
            'total_minor' => 'integer',
            'started_at' => 'immutable_datetime',
        ];
    }
}
