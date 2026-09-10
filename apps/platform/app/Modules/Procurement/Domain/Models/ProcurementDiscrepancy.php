<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

final class ProcurementDiscrepancy extends TenantProcurementModel
{
    protected function casts(): array
    {
        return ['evidence' => 'array', 'resolved_at' => 'immutable_datetime'];
    }
}
