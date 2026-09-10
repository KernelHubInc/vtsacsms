<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/** @property CarbonImmutable|null $captured_at */
final class MaintenanceAttachment extends TenantMaintenanceModel
{
    protected $table = 'maintenance_attachments';

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'captured_at' => 'immutable_datetime'];
    }
}
