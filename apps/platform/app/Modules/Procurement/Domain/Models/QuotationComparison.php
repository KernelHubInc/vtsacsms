<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

final class QuotationComparison extends TenantProcurementModel
{
    protected function casts(): array
    {
        return ['comparison_snapshot' => 'array', 'approved_at' => 'immutable_datetime'];
    }
}
