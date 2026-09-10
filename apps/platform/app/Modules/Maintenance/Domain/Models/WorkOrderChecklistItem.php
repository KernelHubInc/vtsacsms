<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $completed_at */
final class WorkOrderChecklistItem extends TenantMaintenanceModel
{
    protected $table = 'maintenance_work_order_checklist_items';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_required' => 'boolean',
            'requires_photo' => 'boolean',
            'requires_pass' => 'boolean',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
