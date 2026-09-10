<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $duration_seconds
 */
final class DowntimePeriod extends TenantMaintenanceModel
{
    protected $table = 'maintenance_downtime_periods';

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
        ];
    }
}
