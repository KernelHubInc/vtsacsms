<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
final class Warranty extends TenantMaintenanceModel
{
    protected $table = 'maintenance_warranties';

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
