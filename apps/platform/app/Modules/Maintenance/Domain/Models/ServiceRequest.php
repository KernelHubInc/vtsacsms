<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $converted_at
 */
final class ServiceRequest extends TenantMaintenanceModel
{
    protected $table = 'maintenance_service_requests';

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'converted_at' => 'immutable_datetime'];
    }
}
