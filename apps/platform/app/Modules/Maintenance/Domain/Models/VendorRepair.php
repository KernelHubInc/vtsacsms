<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property int $cost_minor
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $received_at
 */
final class VendorRepair extends TenantMaintenanceModel
{
    protected $table = 'maintenance_vendor_repairs';

    protected function casts(): array
    {
        return ['cost_minor' => 'integer', 'sent_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }
}
