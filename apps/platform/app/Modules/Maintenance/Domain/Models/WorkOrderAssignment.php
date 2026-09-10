<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $released_at
 */
final class WorkOrderAssignment extends TenantMaintenanceModel
{
    protected $table = 'maintenance_work_order_assignments';

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
